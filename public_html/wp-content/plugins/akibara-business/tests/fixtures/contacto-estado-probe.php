<?php
/**
 * Fixture para ContactoFormTest: sustituye a la plantilla real del tema vía
 * `locate_template()` para comprobar SÓLO el contrato que `ContactoForm::render()`
 * le debe al tema — que `$akb_contacto_estado` exista ANTES del include, con un
 * valor de la lista blanca ('', 'ok', 'error') — sin arrastrar el theme entero.
 *
 * @package Akibara\Business\Tests
 */

echo isset( $akb_contacto_estado ) ? ( '' === $akb_contacto_estado ? 'VACIO' : $akb_contacto_estado ) : 'NO_DEFINIDA';
