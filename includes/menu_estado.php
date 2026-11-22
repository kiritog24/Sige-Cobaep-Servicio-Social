<?php
/**
 * includes/menu_estado.php — Se incluye justo después del menú lateral (<aside id="sidebar">…).
 * Aplica el estado compacto/desplegado ANTES de que se pinte la página y sin animación, para que al
 * navegar con el menú compacto no "salte" (antes se pintaba desplegado y luego se cerraba).
 * El botón de compactar lo maneja js/responsive.js en todos los portales.
 */
?>
<script>
(function () {
    var b = document.body, t = document.getElementById('collapseToggle'), g = false;
    try { g = localStorage.getItem('sidebarCollapsed') === 'true'; } catch (e) {}
    // Solo en escritorio (con el botón de compactar visible); en teléfono el menú siempre se abre completo
    var escritorio = !!t && getComputedStyle(t).display !== 'none' && t.offsetWidth > 0;
    b.classList.add('sige-sin-animacion');
    b.classList.toggle('sidebar-collapsed', g && escritorio);
    var quitar = function () { b.classList.remove('sige-sin-animacion'); };
    window.addEventListener('load', function () { requestAnimationFrame(function () { requestAnimationFrame(quitar); }); });
    setTimeout(quitar, 2500);
})();
</script>
