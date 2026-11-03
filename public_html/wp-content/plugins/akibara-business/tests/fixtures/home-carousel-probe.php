<?php
/**
 * Fixture para HomeCarouselTest: sustituye a la plantilla real del tema vía
 * `locate_template()` para comprobar SÓLO el contrato que `HomeCarousel::render()`
 * le debe al tema — que `$more_url` venga de `BrandsHubUrl` (R3-editoriales-hub),
 * sin arrastrar el markup del carrusel entero.
 *
 * @package Akibara\Business\Tests
 */

echo isset( $more_url ) ? $more_url : 'NO_DEFINIDA';
