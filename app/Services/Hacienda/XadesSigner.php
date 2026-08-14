<?php

namespace App\Services\Hacienda;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Firma un comprobante electrónico de Costa Rica con una firma XAdES-EPES
 * enveloped usando el certificado .p12 del emisor, como exige Hacienda (v4.4).
 *
 * Implementado nativamente (DOMDocument + openssl, C14N exclusivo, RSA-SHA256)
 * sin depender de una librería externa de XML-DSig.
 */
class XadesSigner
{
    private const DS    = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    private const C14N  = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const SIGNED_PROPS_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    public function sign(string $xml, string $p12Contents, string $pin): string
    {
        $certs = [];
        if (!openssl_pkcs12_read($p12Contents, $certs, $pin)) {
            throw new RuntimeException('No se pudo abrir el certificado .p12 (verifique el PIN). ' . openssl_error_string());
        }

        $privateKey  = openssl_pkey_get_private($certs['pkey']);
        $certPem     = $certs['cert'];
        $certInfo    = openssl_x509_parse($certPem);
        $keyDetails  = openssl_pkey_get_details($privateKey);

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML($xml);
        $root = $doc->documentElement;

        $sigId      = 'Signature-' . Str::uuid();
        $sigValueId = $sigId . '-SignatureValue';
        $keyInfoId  = $sigId . '-KeyInfo';
        $objectId   = $sigId . '-Object';
        $spId       = $sigId . '-SignedProperties';
        $ref1Id     = $sigId . '-Reference1';

        $docDigest = $this->sha256b64($root->C14N(true, false));

        $signature = $doc->createElementNS(self::DS, 'ds:Signature');
        $signature->setAttribute('Id', $sigId);
        $root->appendChild($signature);

        $signedInfo    = $this->buildSignedInfo($doc, $ref1Id, $spId, $keyInfoId);
        $signature->appendChild($signedInfo);

        $signatureValue = $doc->createElementNS(self::DS, 'ds:SignatureValue', '');
        $signatureValue->setAttribute('Id', $sigValueId);
        $signature->appendChild($signatureValue);

        $keyInfo = $this->buildKeyInfo($doc, $keyInfoId, $certPem, $keyDetails);
        $signature->appendChild($keyInfo);

        $object = $this->buildObject($doc, $objectId, $sigId, $spId, $ref1Id, $certPem, $certInfo);
        $signature->appendChild($object);

        $signedProps   = $doc->getElementById($spId) ?: $this->findById($signature, 'Id', $spId);
        $spDigest      = $this->sha256b64($signedProps->C14N(true, false));
        $keyInfoDigest = $this->sha256b64($keyInfo->C14N(true, false));

        $digestNodes = $signedInfo->getElementsByTagNameNS(self::DS, 'DigestValue');
        $digestNodes->item(0)->nodeValue = $docDigest;
        $digestNodes->item(1)->nodeValue = $spDigest;
        $digestNodes->item(2)->nodeValue = $keyInfoDigest;

        $canonicalSignedInfo = $signedInfo->C14N(true, false);
        $signatureBinary = '';
        if (!openssl_sign($canonicalSignedInfo, $signatureBinary, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falló la firma RSA-SHA256: ' . openssl_error_string());
        }
        $signatureValue->nodeValue = base64_encode($signatureBinary);

        return $doc->saveXML();
    }

    private function buildSignedInfo(DOMDocument $doc, string $ref1Id, string $spId, string $keyInfoId): DOMElement
    {
        $signedInfo = $doc->createElementNS(self::DS, 'ds:SignedInfo');

        $c14n = $doc->createElementNS(self::DS, 'ds:CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', self::C14N);
        $signedInfo->appendChild($c14n);

        $sigMethod = $doc->createElementNS(self::DS, 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', self::RSA_SHA256);
        $signedInfo->appendChild($sigMethod);

        $ref1 = $doc->createElementNS(self::DS, 'ds:Reference');
        $ref1->setAttribute('Id', $ref1Id);
        $ref1->setAttribute('URI', '');
        $transforms = $doc->createElementNS(self::DS, 'ds:Transforms');
        $transforms->appendChild($this->transform($doc, self::ENVELOPED));
        $transforms->appendChild($this->transform($doc, self::C14N));
        $ref1->appendChild($transforms);
        $ref1->appendChild($this->digestMethod($doc));
        $ref1->appendChild($doc->createElementNS(self::DS, 'ds:DigestValue', ''));
        $signedInfo->appendChild($ref1);

        $ref2 = $doc->createElementNS(self::DS, 'ds:Reference');
        $ref2->setAttribute('Type', self::SIGNED_PROPS_TYPE);
        $ref2->setAttribute('URI', '#' . $spId);
        $transforms2 = $doc->createElementNS(self::DS, 'ds:Transforms');
        $transforms2->appendChild($this->transform($doc, self::C14N));
        $ref2->appendChild($transforms2);
        $ref2->appendChild($this->digestMethod($doc));
        $ref2->appendChild($doc->createElementNS(self::DS, 'ds:DigestValue', ''));
        $signedInfo->appendChild($ref2);

        $ref3 = $doc->createElementNS(self::DS, 'ds:Reference');
        $ref3->setAttribute('URI', '#' . $keyInfoId);
        $transforms3 = $doc->createElementNS(self::DS, 'ds:Transforms');
        $transforms3->appendChild($this->transform($doc, self::C14N));
        $ref3->appendChild($transforms3);
        $ref3->appendChild($this->digestMethod($doc));
        $ref3->appendChild($doc->createElementNS(self::DS, 'ds:DigestValue', ''));
        $signedInfo->appendChild($ref3);

        return $signedInfo;
    }

    private function buildKeyInfo(DOMDocument $doc, string $keyInfoId, string $certPem, array $keyDetails): DOMElement
    {
        $keyInfo = $doc->createElementNS(self::DS, 'ds:KeyInfo');
        $keyInfo->setAttribute('Id', $keyInfoId);

        $x509Data = $doc->createElementNS(self::DS, 'ds:X509Data');
        $x509Data->appendChild($doc->createElementNS(self::DS, 'ds:X509Certificate', $this->pemBody($certPem)));
        $keyInfo->appendChild($x509Data);

        $keyValue = $doc->createElementNS(self::DS, 'ds:KeyValue');
        $rsa = $doc->createElementNS(self::DS, 'ds:RSAKeyValue');
        $rsa->appendChild($doc->createElementNS(self::DS, 'ds:Modulus', base64_encode($keyDetails['rsa']['n'])));
        $rsa->appendChild($doc->createElementNS(self::DS, 'ds:Exponent', base64_encode($keyDetails['rsa']['e'])));
        $keyValue->appendChild($rsa);
        $keyInfo->appendChild($keyValue);

        return $keyInfo;
    }

    private function buildObject(
        DOMDocument $doc,
        string $objectId,
        string $sigId,
        string $spId,
        string $ref1Id,
        string $certPem,
        array $certInfo
    ): DOMElement {
        $object = $doc->createElementNS(self::DS, 'ds:Object');
        $object->setAttribute('Id', $objectId);

        $qp = $doc->createElementNS(self::XADES, 'xades:QualifyingProperties');
        $qp->setAttribute('Target', '#' . $sigId);

        $sp = $doc->createElementNS(self::XADES, 'xades:SignedProperties');
        $sp->setAttribute('Id', $spId);

        $ssp = $doc->createElementNS(self::XADES, 'xades:SignedSignatureProperties');
        $ssp->appendChild($doc->createElementNS(self::XADES, 'xades:SigningTime', now()->format('Y-m-d\TH:i:sP')));

        $signingCert = $doc->createElementNS(self::XADES, 'xades:SigningCertificate');
        $cert = $doc->createElementNS(self::XADES, 'xades:Cert');

        $certDigest = $doc->createElementNS(self::XADES, 'xades:CertDigest');
        $certDigest->appendChild($this->digestMethod($doc));
        $der = base64_decode($this->pemBody($certPem));
        $certDigest->appendChild($doc->createElementNS(self::DS, 'ds:DigestValue', $this->sha256b64($der)));
        $cert->appendChild($certDigest);

        $issuerSerial = $doc->createElementNS(self::XADES, 'xades:IssuerSerial');
        $issuerSerial->appendChild($doc->createElementNS(self::DS, 'ds:X509IssuerName', $this->issuerName($certInfo['issuer'] ?? [])));
        $issuerSerial->appendChild($doc->createElementNS(self::DS, 'ds:X509SerialNumber', $certInfo['serialNumber'] ?? '0'));
        $cert->appendChild($issuerSerial);
        $signingCert->appendChild($cert);
        $ssp->appendChild($signingCert);

        $policy = config('hacienda.signature_policy');
        $spi = $doc->createElementNS(self::XADES, 'xades:SignaturePolicyIdentifier');
        $spId2 = $doc->createElementNS(self::XADES, 'xades:SignaturePolicyId');
        $sigPolicyId = $doc->createElementNS(self::XADES, 'xades:SigPolicyId');
        $sigPolicyId->appendChild($doc->createElementNS(self::XADES, 'xades:Identifier', $policy['url']));
        $sigPolicyId->appendChild($doc->createElementNS(self::XADES, 'xades:Description', ''));
        $spId2->appendChild($sigPolicyId);
        $sigPolicyHash = $doc->createElementNS(self::XADES, 'xades:SigPolicyHash');
        $sigPolicyHash->appendChild($this->digestMethod($doc, $policy['digest_algo'] ?? 'sha256'));
        $sigPolicyHash->appendChild($doc->createElementNS(self::DS, 'ds:DigestValue', $policy['digest_value']));
        $spId2->appendChild($sigPolicyHash);
        $spi->appendChild($spId2);
        $ssp->appendChild($spi);

        $sp->appendChild($ssp);

        $sdop = $doc->createElementNS(self::XADES, 'xades:SignedDataObjectProperties');
        $dof = $doc->createElementNS(self::XADES, 'xades:DataObjectFormat');
        $dof->setAttribute('ObjectReference', '#' . $ref1Id);
        $dof->appendChild($doc->createElementNS(self::XADES, 'xades:MimeType', 'text/xml'));
        $sdop->appendChild($dof);
        $sp->appendChild($sdop);

        $qp->appendChild($sp);
        $object->appendChild($qp);

        return $object;
    }

    private function transform(DOMDocument $doc, string $algorithm): DOMElement
    {
        $t = $doc->createElementNS(self::DS, 'ds:Transform');
        $t->setAttribute('Algorithm', $algorithm);
        return $t;
    }

    private function digestMethod(DOMDocument $doc, string $algo = 'sha256'): DOMElement
    {
        $dm = $doc->createElementNS(self::DS, 'ds:DigestMethod');
        $dm->setAttribute('Algorithm', $algo === 'sha1'
            ? 'http://www.w3.org/2000/09/xmldsig#sha1'
            : self::SHA256);
        return $dm;
    }

    private function issuerName(array $issuer): string
    {
        $parts = [];
        foreach (array_reverse($issuer) as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                $parts[] = $key . '=' . $v;
            }
        }
        return implode(',', $parts);
    }

    private function findById(DOMElement $context, string $attr, string $id): ?DOMElement
    {
        foreach ($context->getElementsByTagName('*') as $node) {
            if ($node->getAttribute($attr) === $id) {
                return $node;
            }
        }
        return null;
    }

    private function pemBody(string $pem): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private function sha256b64(string $data): string
    {
        return base64_encode(hash('sha256', $data, true));
    }
}
