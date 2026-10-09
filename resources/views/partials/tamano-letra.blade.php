{{--
    Tamaño de letra elegido en este equipo (ver x-tamano-letra). Va en el
    <head> y antes del CSS para aplicarse antes de pintar: si no, la página
    aparece chica y salta al tamaño elegido.

    En porcentaje sobre <html> y no en px: así se suma al tamaño que la persona
    ya tenga configurado en su navegador en vez de pisarlo (WCAG 1.4.4). Todo
    el sistema está en rem, así que escala junto: texto, campos y botones.
--}}
<script>
    (function () {
        try {
            var tamano = localStorage.getItem('tamanoLetra');
            if (['112.5', '125', '150'].indexOf(tamano) !== -1) {
                document.documentElement.style.fontSize = tamano + '%';
            }
        } catch (e) {}
    })();
</script>
