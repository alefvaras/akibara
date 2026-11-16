// @ts-check
/**
 * El aviso «Gana N Puntos Akibara» de la ficha (akibara-rewards, PdpNotice),
 * tal como lo miran `puntos` y `rewards-aviso-de-puntos`.
 *
 * El aviso se puede apagar en el panel de Puntos (`akb_rewards_pdp_notice_enabled`),
 * y eso se DECLARA con `AKB_AVISO_PUNTOS=apagado`: no se deduce de que la ficha
 * no lo pinte, que es justo lo que se prueba (REV-F-R-rev1-1).
 *
 * Y la declaración se comprueba en la ficha. Antes cada prueba se saltaba en su
 * primera línea, sin abrir nada: una variable olvidada en el shell o en el CI
 * después de volver a encender el aviso saltaba para siempre todas las pruebas
 * del aviso, y la suite salía en verde aunque alguien lo borrara, lo plegara o
 * lo duplicara (REV-F-R-rev2-3). Ahora, con la declaración puesta, la prueba
 * abre la ficha que ya eligió y exige que NO haya aviso; sólo entonces se salta
 * el resto. Así el interruptor del panel también queda probado.
 */

import { expect, test } from '@playwright/test';
import { aLaVista } from './a-la-vista.mjs';

/** El aviso, por su clase real. */
export const AVISO = '.akb-rw-pdp-points';

/**
 * La promesa que pidió el dueño: «480 Puntos Akibara = $480». El «$» va atado
 * al número de puntos: el invitado ve también el bono («más 500 de bienvenida
 * = $500»), y un «$» suelto en cualquier parte del aviso seguía cumpliendo
 * aunque la promesa principal perdiera su equivalencia (REV-F-R-1-1).
 */
export const PROMESA = /\d[\d.]*\s*Puntos Akibara\s*=\s*\$\s*\d/;

/** El mensaje del rojo cuando la ficha no muestra el aviso. */
export const FALTA_EL_AVISO = 'la ficha muestra el aviso de puntos (apagado a propósito: se declara con AKB_AVISO_PUNTOS=apagado)';

const APAGADO = 'apagado' === process.env.AKB_AVISO_PUNTOS;

/**
 * Exige que el cliente LEA la promesa, no sólo el aviso que la lleva.
 *
 * La promesa va en un <strong>: escondido él solo (recortado, subido fuera del
 * documento, con letra de 1 px, transparente…) o cortado a lo ancho («480
 * Punt…»), la caja del aviso no cambia y el resto del aviso se sigue viendo
 * (REV-F-R-3-1, REV-M-R-2-1 a 2-3, REV-Z-R-1o-1). Así que se mide el elemento
 * más interior del aviso cuyo texto la contiene —hoy ese <strong>; si el
 * marcado cambia, el propio aviso— y se mira en píxeles, letra a letra
 * (soporte/a-la-vista.mjs).
 *
 * @param {import('@playwright/test').Page} page Página con la ficha abierta.
 * @returns {Promise<void>}
 */
export async function seLeeLaPromesa( page ) {
	const promesa = page.locator( `${ AVISO }, ${ AVISO } *` ).filter( { hasText: PROMESA } ).last();
	await aLaVista( promesa, 'la promesa «N Puntos Akibara = $N» se lee en la ficha' );
}

/**
 * Con el aviso declarado apagado, comprueba en la ficha abierta que no se pinta
 * y salta el resto de la prueba. Sin la declaración no hace nada.
 *
 * @param {import('@playwright/test').Page} page Página con la ficha ya abierta.
 * @returns {Promise<void>}
 */
export async function siDeclaradoApagado( page ) {
	if ( ! APAGADO ) {
		return;
	}
	await expect( page.locator( AVISO ), 'declarado apagado, pero la ficha pinta el aviso: quita AKB_AVISO_PUNTOS' ).toHaveCount( 0 );
	test.skip( true, 'el aviso de puntos está apagado en este entorno (AKB_AVISO_PUNTOS=apagado) y la ficha, como debe, no lo pinta' );
}
