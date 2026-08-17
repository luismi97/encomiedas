<?php

namespace Tests\Feature\Hacienda;

use App\Services\Hacienda\XadesSigner;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Tests\TestCase;

/**
 * La firma es el punto donde un error no se nota hasta que Hacienda rechaza
 * TODOS los comprobantes. Aquí se verifica igual que lo haría el validador:
 * recalculando cada digest y comprobando la firma RSA contra el certificado.
 */
class XadesSignerTest extends TestCase
{
    private const PIN = 'clave-de-prueba';
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /** Certificado autofirmado generado al vuelo: no se guarda ninguno en el repo. */
    private function p12(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL no puede generar llaves en este entorno.');
        }

        $csr = openssl_csr_new([
            'countryName'            => 'CR',
            'stateOrProvinceName'    => 'San Jose',
            'organizationName'       => 'Encomiendas de Prueba',
            'commonName'             => 'PRUEBA 3101123456',
        ], $key, ['digest_alg' => 'sha256']);

        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

        $p12 = '';
        openssl_pkcs12_export($cert, $p12, $key, self::PIN);

        return $p12;
    }

    private function sampleXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
            . '<Clave>50614082600310112345600100001010000000001100000001</Clave>'
            . '<NumeroConsecutivo>00100001010000000001</NumeroConsecutivo>'
            . '</FacturaElectronica>';
    }

    private function signedDocument(): DOMDocument
    {
        $signed = app(XadesSigner::class)->sign($this->sampleXml(), $this->p12(), self::PIN);

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($signed);

        return $doc;
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::DS);
        $xpath->registerNamespace('xades', self::XADES);

        return $xpath;
    }

    public function test_el_pin_equivocado_falla_con_un_mensaje_claro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PIN');

        app(XadesSigner::class)->sign($this->sampleXml(), $this->p12(), 'pin-incorrecto');
    }

    public function test_la_firma_rsa_verifica_contra_el_certificado_incrustado(): void
    {
        $doc = $this->signedDocument();
        $xpath = $this->xpath($doc);

        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $this->assertNotNull($signedInfo, 'Falta el nodo SignedInfo.');

        $signatureValue = base64_decode($xpath->query('//ds:SignatureValue')->item(0)->nodeValue);
        $certBody = $xpath->query('//ds:X509Certificate')->item(0)->nodeValue;
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $certBody), 64, "\n") . "-----END CERTIFICATE-----\n";

        // Exactamente lo que hace el validador: C14N exclusivo del SignedInfo
        // y verificación RSA-SHA256 con la llave pública del certificado.
        $verified = openssl_verify(
            $signedInfo->C14N(true, false),
            $signatureValue,
            openssl_pkey_get_public($pem),
            OPENSSL_ALGO_SHA256
        );

        $this->assertSame(1, $verified, 'La firma RSA no verifica contra el certificado incrustado.');
    }

    public function test_el_digest_del_documento_corresponde_al_xml_sin_la_firma(): void
    {
        $doc = $this->signedDocument();
        $xpath = $this->xpath($doc);

        $declared = $xpath->query('//ds:SignedInfo/ds:Reference[@URI=""]/ds:DigestValue')->item(0)->nodeValue;

        // Transformación "enveloped": se quita la firma y se recanoniza.
        $signature = $xpath->query('//ds:Signature')->item(0);
        $signature->parentNode->removeChild($signature);
        $recalculated = base64_encode(hash('sha256', $doc->documentElement->C14N(true, false), true));

        $this->assertSame($declared, $recalculated, 'El digest del documento no cuadra: Hacienda lo leería como XML modificado tras firmar.');
    }

    public function test_el_digest_de_las_propiedades_firmadas_corresponde_a_su_contenido(): void
    {
        $doc = $this->signedDocument();
        $xpath = $this->xpath($doc);

        $signedProperties = $xpath->query('//xades:SignedProperties')->item(0);
        $this->assertNotNull($signedProperties, 'Falta el nodo SignedProperties (XAdES).');

        $id = $signedProperties->getAttribute('Id');
        $declared = $xpath->query('//ds:Reference[@URI="#' . $id . '"]/ds:DigestValue')->item(0)->nodeValue;

        $this->assertSame(
            base64_encode(hash('sha256', $signedProperties->C14N(true, false), true)),
            $declared
        );
    }

    public function test_la_firma_declara_la_politica_de_firma_que_exige_hacienda(): void
    {
        $doc = $this->signedDocument();
        $xpath = $this->xpath($doc);

        $identifier = $xpath->query('//xades:SigPolicyId/xades:Identifier')->item(0);
        $digest = $xpath->query('//xades:SigPolicyHash/ds:DigestValue')->item(0);

        $this->assertNotNull($identifier, 'Falta SignaturePolicyIdentifier: sin él la firma no es XAdES-EPES.');
        $this->assertSame(config('hacienda.signature_policy.url'), $identifier->nodeValue);
        $this->assertSame(config('hacienda.signature_policy.digest_value'), $digest->nodeValue);
    }
}
