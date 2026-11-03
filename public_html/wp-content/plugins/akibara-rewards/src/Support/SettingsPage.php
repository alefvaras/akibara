<?php
/**
 * SettingsPage — Panel de configuración de montos y superficies del
 * programa de fidelización y reseñas.
 *
 * Una sola pantalla bajo WooCommerce, en el idioma de la tienda: cada campo
 * es una option con default (los defaults replican la config de prod de los
 * terceros que reemplazamos), guardado por admin-post con nonce+capability y
 * sanitización por tipo. Sin JS, sin frameworks: liviana.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Calculator;
use Akibara\Rewards\Reviews\AdminOverview;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class SettingsPage {

	public const CAPABILITY = 'manage_woocommerce';
	public const ACTION     = 'akb_rewards_save_settings';
	public const SLUG       = 'akb-rewards-settings';

	/**
	 * Avisos de un minuto del último guardado, POR USUARIO y con payload
	 * (`[ ['tipo' => 'error'|'warning', 'texto' => '…'], … ]`), como los de
	 * AdminAdjust. La marca global anterior se comía el aviso del otro cuando
	 * dos administradores guardaban a la vez.
	 */
	public const TRANSIENT_AVISOS = 'akb_rw_avisos_';

	/**
	 * Vara del ejemplo en pesos de los avisos.
	 *
	 * Es una cifra redonda de referencia: un valor de $10.000 deja el cálculo
	 * transparente e independiente de variaciones en el catálogo.
	 */
	private const EJEMPLO_COMPRA = 10000;

	/**
	 * Ajustes que se leen dentro del HTML de CADA ficha y le dicen algo al
	 * cliente que tiene que ser verdad: cambiar uno lanza la purga TOTAL.
	 *
	 * La copia de invitado de una ficha vive en DOS sitios: LiteSpeed en el
	 * origen y Cloudflare en el borde (hasta 10 minutos; ver EdgePurge en
	 * akibara-business). En esta zona sólo `litespeed_purge_all` llega al borde:
	 * es la única llamada de LiteSpeed a Cloudflare (`purge_everything`,
	 * dentro de `Purge::_purge_all()`), y producción la tiene encendida.
	 * Su precio es alto —vacía también el CSS/JS minificado, la caché de objetos
	 * y OPcache, y enfría el borde entero—, así que se paga sólo por una promesa:
	 *
	 * - El aviso «Gana N Puntos Akibara» y todo lo que su promesa lee: con la
	 *   tasa cambiada en el panel y la ficha sin purgar, la ficha prometía una
	 *   cifra y la acreditación daba otra. Incluye los interruptores de los
	 *   carriles: con «Dar puntos por compra» apagado la promesa es cero y el
	 *   aviso no sale. La suite pinta el aviso y exige que todo lo que lee del
	 *   panel esté aquí, y no en FORMA_DE_CADA_FICHA.
	 * - El aviso de divulgación en el listado de reseñas: le dice al comprador
	 *   que la reseña ganó puntos.
	 */
	private const PROMESA_EN_CADA_FICHA = array(
		'akb_rewards_disclosure_enabled',
		'akb_rewards_pdp_notice_enabled',
		'akb_rewards_earn_enabled',
		'akb_rewards_earn_points',
		'akb_rewards_earn_amount',
		'akb_rewards_redeem_value',
		'akb_rewards_welcome_enabled',
		'akb_rewards_welcome_bonus',
		'akb_rewards_first_purchase_min',
		'akb_rewards_program_start',
	);

	/**
	 * Ajustes de presentación compartidos por las fichas. Un cambio invalida
	 * el HTML de origen mediante `litespeed_purge` con `*`, sin vaciar assets,
	 * caché de objetos ni OPcache. La portada se invalida con Carousel::flush().
	 */
	private const FORMA_DE_CADA_FICHA = array(
		'akb_rewards_schema_exclude_rewarded',
		'akb_rewards_carousel_title',
	);

	/**
	 * Catálogo de campos: option → [tipo, default, etiqueta, ayuda, sección].
	 * ÚNICA fuente de la pantalla y del guardado — añadir una option nueva es
	 * añadir UNA fila acá. Opcionales: `min`/`max` de un entero y `largo` de un
	 * texto; cada uno pone el tope en el formulario y en el guardado a la vez.
	 *
	 * @var array<string,array{t:string,d:mixed,l:string,h:string,s:string,min?:int,max?:int,largo?:int}>
	 */
	private const FIELDS = array(
		'akb_rewards_earn_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar puntos por compra',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_earn_points'              => array(
			't'   => 'int',
			'd'   => 2,
			// 10x lo vigente (2). Con el tramo de $100 eso devuelve el 20 %, que
			// sólo entra si el techo de devolución de abajo lo permite: quien
			// frena antes es la guarda de razón. Este tope es sólo la baranda
			// contra el tecleo (200, 2000).
			'max' => 20,
			'l'   => 'Puntos por tramo de compra',
			'h'   => 'Con 2 puntos por cada $100, una compra de $10.000 gana 200. El techo de devolución de más abajo manda sobre este máximo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_earn_amount'              => array(
			't'   => 'int',
			'd'   => 100,
			// El peligro de este campo va hacia ABAJO (tramo de $1 = 200 % de
			// devolución) y lo cubre la guarda de razón, no un max. Hacia
			// arriba, lo absurdo es un tramo mayor que cualquier compra de la
			// tienda (el tomo más caro publicado son $83.000). Para apagar la
			// acumulación está el 0 y el interruptor del carril.
			'max' => 1000000,
			'l'   => 'Tamaño del tramo ($)',
			'h'   => 'Tramo en pesos de la acumulación. 0 APAGA los puntos por compra (nadie acredita).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_min_points' => array(
			't'   => 'int',
			'd'   => 100,
			// Derivado, no inventado: 5.000 puntos a $1 son los $5.000 del tope
			// de descuento por pedido. El saldo más alto de la tienda hoy son
			// 2.658 puntos.
			'max' => 5000,
			'l'   => 'Mínimo de puntos por canje',
			'h'   => 'El cliente no puede canjear menos que esto de una vez. 0 = sin mínimo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_max_pct' => array(
			't' => 'int',
			'd' => \Akibara\Rewards\Support\Options::DEFAULT_REDEEM_MAX_PCT,
			// Es un porcentaje: el propio formulario impide guardar fuera de
			// rango, no sólo el accessor.
			'min' => 0,
			'max' => 100,
			'l' => 'Máximo del pedido que se paga con puntos (%)',
			'h' => 'Cuánto de un pedido puede cubrir el cliente con puntos. Con 50, en una compra de $40.000 puede usar hasta $20.000 en puntos y el resto lo paga. 0 = sin tope (podría pagar el pedido entero con puntos). El despacho nunca se paga con puntos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_redeem_max_amount' => array(
			't'   => 'int',
			'd'   => \Akibara\Rewards\Support\Options::DEFAULT_REDEEM_MAX_AMOUNT,
			'min' => 0,
			// 10x el vigente. Con el tomo promedio en $14.334, $50.000 ya paga
			// un carrito de tres tomos entero.
			'max' => 50000,
			'l'   => 'Máximo de descuento por pedido ($)',
			'h'   => 'Máximo descuento en pesos que el cliente puede canjear por compra. Si pones los dos topes, manda el más estricto. 0 = sin tope en pesos.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_min_cart'          => array(
			't'   => 'int',
			'd'   => 50000,
			'min' => 0,
			// 10x lo decidido: por encima ya casi ninguna compra de la tienda
			// podría canjear (la primera compra más alta medida fueron $227.700).
			'max' => 500000,
			'l'   => 'Carrito mínimo para canjear ($)',
			'h'   => 'El canje de puntos se activa cuando los productos del carrito suman al menos este monto (con IVA y cupones, sin despacho). Es lo que permite regalar puntos sin que se farmeen: sólo se gastan dentro de una compra de verdad. 0 = sin mínimo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_redeem_value'             => array(
			't'   => 'int',
			'd'   => 1,
			'min' => 1,
			// 10x lo vigente. Con la acumulación de hoy (2 por cada $100) un
			// punto a $10 devuelve el 20 %: lo frena la guarda de razón salvo
			// que el techo de abajo lo permita.
			'max' => 10,
			'l'   => 'Pesos de descuento por punto canjeado',
			'h'   => '1 = un peso por punto (IVA incluido, exacto en el total). El techo de devolución de abajo manda sobre este máximo.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_max_giveback_pct'         => array(
			't'   => 'int',
			'd'   => \Akibara\Rewards\Support\Options::DEFAULT_MAX_GIVEBACK_PCT,
			'min' => 1,
			'max' => \Akibara\Rewards\Support\Options::HARD_MAX_GIVEBACK_PCT,
			'l'   => 'Techo de devolución del programa (%)',
			'h'   => 'Cuánto de cada compra puede volver al cliente en puntos, juntando los tres campos de arriba (puntos por tramo ÷ tamaño del tramo × pesos por punto). Hoy la tienda devuelve el 2 %. Un guardado que supere este techo no entra: se conserva lo que había y se explica en pesos.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_welcome_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar bono de bienvenida (primera compra)',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_welcome_bonus'            => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente, y justo el máximo canjeable por pedido: un bono
			// por encima del techo promete una compra que el carrito no deja
			// pagar con puntos.
			'max' => 5000,
			'l'   => 'Bono de bienvenida',
			'h'   => 'Se paga con la PRIMERA compra de la persona, aunque «Dar puntos por compra» esté apagado. Uno por persona (ver los interruptores de abajo). Nunca por solo registrarse. 0 lo apaga.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_min'       => array(
			't'   => 'int',
			'd'   => 0,
			'min' => 0,
			// 10x la mediana de la primera compra medida en producción ($24.000):
			// por encima, el bono deja de ser de bienvenida.
			'max' => 240000,
			'l'   => 'Compra mínima para bono y referido ($)',
			'h'   => 'Si pones un monto, la primera compra tiene que sumar al menos eso en productos pagados con dinero (con IVA y cupones; sin despacho ni puntos) para dar el bono de bienvenida y el premio a quien invitó. 0 = sin mínimo (recomendado): basta con que no se pague entera con puntos; el freno contra el abuso lo pone el carrito mínimo para canjear.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_rut'    => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el RUT',
			'h' => 'Si el RUT del pedido ya aparece en una compra anterior (desde el inicio del programa), no hay bono ni premio a quien invitó, aunque sea otra cuenta.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_phone'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el teléfono',
			'h' => 'Igual, con el teléfono (se compara el número sin +56 ni espacios). Apágalo si ves falsos positivos, por ejemplo un teléfono familiar compartido.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_email'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Uno por persona: mirar el correo',
			'h' => 'Igual, con el correo del pedido. La cuenta del cliente se compara siempre.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_welcome_teaser_enabled'   => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Mostrar «puntos esperándote»',
			'h' => 'Promete el bono en el registro y en Mi cuenta hasta que se active con la primera compra. Nada se paga por solo registrarse.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_award_status'             => array(
			't' => 'status',
			'd' => Options::DEFAULT_AWARD_STATUS,
			'l' => 'Estado del pedido que acredita',
			'h' => 'Los puntos se pagan cuando el pedido llega a este estado o a uno posterior. «Procesando» = la pasarela confirmó el pago, así el cliente ve sus puntos apenas paga; «Listo para enviar» espera a que prepares el despacho. Si la venta se cae (cancelado, fallido o reembolsado), los puntos se retiran solos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_program_start'            => array(
			't' => 'text',
			'd' => '2026-08-17 00:00:00',
			'l' => 'Inicio de vigencia del programa',
			'h' => 'Pedidos creados antes de esta fecha no acreditan (formato AAAA-MM-DD HH:MM:SS).',
			's' => 'Puntos por compra',
		),
		'akb_rewards_expiry_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Hacer vencer los puntos',
			'h' => 'Apagado: los puntos duran para siempre. Encendido: duran los meses de abajo y se avisa por correo antes. El cambio se aplica al guardar.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_expiry_months'            => array(
			't'   => 'int',
			'd'   => 12,
			// 10x lo vigente: diez años. Para «no vencen jamás» están el 0 y el
			// interruptor.
			'max' => 120,
			'l'   => 'Meses de vida de los puntos',
			'h'   => 'Cuánto tiempo tiene el cliente para gastarlos. 0 = no vencen jamás. Vencen primero los más antiguos (FIFO).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_expiry_warn_days'         => array(
			't'   => 'int',
			'd'   => 30,
			// 12x lo vigente y un año redondo. Avisar con más anticipación que
			// la vida de los puntos manda el correo el día de la compra; la
			// guarda de coherencia lo frena antes de llegar acá.
			'max' => 365,
			'l'   => 'Aviso previo al vencimiento (días)',
			'h'   => 'Correo «tienes N puntos por vencer» con esta anticipación (máx. uno al mes por cliente). 0 = sin aviso.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_invite_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Invitar a reseñar por correo',
			'h' => 'Un correo con enlace personal por pedido pagado, después del despacho o retiro. Los productos a pedido y preventa deben estar listos. Cambiar ajustes conserva las fechas ya programadas y no reenvía invitaciones anteriores.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Despacho: estado que inicia la espera',
			'h' => 'Elige el estado que usas al entregar el pedido al transportista, por ejemplo «Listo para enviar». Puedes elegir el estado de despacho que use tu operación, con cualquier transportista. «Procesando» sólo confirma pago, no despacho.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_delay_days' => array(
			't'   => 'int',
			'd'   => 3,
			// 10x lo vigente y la vida por defecto del enlace: invitar a
			// reseñar más de un mes después del pedido no lo lee nadie.
			'max' => 30,
			'l'   => 'Despacho: días corridos antes de invitar',
			'h'   => 'Se cuentan desde la entrada al estado de despacho, no desde la compra ni el pago. Es un plazo estimado de recepción. Las invitaciones ya programadas conservan su fecha.',
			's'   => 'Reseñas',
		),
		'akb_rewards_invite_pickup_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Entrega directa o retiro: estado que inicia la espera',
			'h' => 'Por ejemplo «Completado» cuando entregas personalmente el pedido o lo retiran. No necesita pasar por el estado de despacho. Si ya había una invitación en cola, conserva su fecha y no crea otra. Si eliges el mismo estado en ambas rutas, se usa el plazo de despacho.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_pickup_delay_days' => array(
			't' => 'int',
			'd' => 6,
			'max' => 30,
			'l' => 'Entrega directa o retiro: días corridos antes de invitar',
			'h' => 'Espera después de la entrega o retiro. Sólo se aplica si ésta fue la primera ruta que programó la invitación.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_token_days' => array(
			't'   => 'int',
			'd'   => 30,
			// 12x lo vigente. Un enlace personal que vive más de un año es un
			// token eterno en la bandeja del cliente.
			'max' => 365,
			'l'   => 'Vida del enlace de reseña (días)',
			'h'   => 'Pasado este plazo el enlace personal vence.',
			's'   => 'Reseñas',
		),
		'akb_rewards_reviews_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar puntos por reseña con foto',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Reseñas',
		),
		'akb_rewards_review_points'            => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente y el máximo canjeable por pedido: una reseña no
			// puede pagar más que el techo de un canje.
			'max' => 5000,
			'l'   => 'Puntos por reseña con foto aprobada',
			'h'   => 'La invitación por correo solicita puntos y exige foto. Se acreditan al aprobar una reseña elegible. Las nuevas reseñas voluntarias de la ficha admiten foto opcional y no generan puntos; las anteriores conservan su política vigente. 0 apaga el programa de reseñas.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_every_n'           => array(
			't'   => 'int',
			'd'   => 1,
			// El min 1 es el que FALTABA: el panel dejaba guardar 0 y
			// Options::review_every_n() leía 1 — la pantalla mostraba un
			// número que no regía. El max 10: premiar una de cada diez ya es
			// no premiar, y para apagarlo está «Puntos por reseña» en 0.
			'min' => 1,
			'max' => 10,
			'l'   => 'Premiar cada cuántas reseñas',
			'h'   => '1 = se premia cada reseña elegible. 3 = se premia la tercera, la sexta… (las intermedias marcan su avance). La reseña de invitación debe tener foto y cumplir los requisitos. Las nuevas reseñas voluntarias de la ficha no generan puntos; las anteriores conservan su política vigente.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_max_per_user'      => array(
			't'   => 'int',
			'd'   => 0,
			// 10x el valor VIVO en producción (5), no el default del catálogo
			// (0 = sin tope): medido contra la fila real para que el primer
			// guardado no le baje el número al dueño.
			'max' => 50,
			'l'   => 'Tope de premios por cliente',
			'h'   => '0 = sin tope. Con 5, un cliente puede conservar hasta 5 premios de reseña. Retirar una reseña premiada revierte sus puntos y libera ese cupo.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_require_verified'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Exigir compra verificada',
			'h' => 'Solo premia reseñas de quien compró ese producto en la tienda.',
			's' => 'Reseñas',
		),
		'akb_rewards_moderate_reviews'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Moderación obligatoria',
			'h' => 'Exige aprobación en el formulario nativo de las fichas. Las reseñas recibidas por invitación siempre quedan pendientes. Se revisan en Productos → Valoraciones.',
			's' => 'Reseñas',
		),
		'akb_rewards_review_nudge_enabled'     => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Invitación a reseñar en el correo',
			'h' => 'Un recordatorio informativo en los correos elegidos. La invitación con enlace personal se envía aparte, después del plazo de despacho o retiro.',
			's' => 'Reseñas',
		),
		'akb_rewards_nudge_email_ids'          => array(
			't' => 'emails',
			'd' => 'customer_completed_order',
			'l' => 'Correos que llevan la invitación',
			'h' => 'Marca los correos que llevan el recordatorio informativo de reseñas. Sin ninguno marcado, no aparece; esto no apaga la invitación automática con enlace personal.',
			's' => 'Reseñas',
		),
		'akb_rewards_disclosure_enabled'       => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Avisar en la reseña que ganó puntos',
			'h' => 'Añade bajo la reseña premiada «Quien escribió esta reseña ganó Puntos Akibara por publicarla con foto» en la ficha. El carrusel tiene un control adicional para mostrar u ocultar este aviso sólo en la portada. Permite que quien la lea sepa que recibió un incentivo, independientemente de las estrellas que haya dado.',
			's' => 'Reseñas',
		),
		'akb_rewards_schema_exclude_rewarded'  => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Sacar de Google el texto de las reseñas premiadas',
			// El filtro de JSON-LD es independiente del aviso visible en la ficha.
			'h' => 'Este interruptor no cambia lo que se ve en la ficha: sólo decide si el texto de las reseñas premiadas viaja en los datos que Google lee de cada ficha. La nota media y el total de reseñas las siguen contando, porque son los mismos números que se ven en la ficha. Por defecto viaja.',
			's' => 'Reseñas',
		),
		'akb_rewards_referral_enabled'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Programa de referidos',
			'h' => 'Cada cliente tiene su enlace en Mi cuenta → Puntos Akibara.',
			's' => 'Referidos',
		),
		'akb_rewards_referral_points'          => array(
			't'   => 'int',
			'd'   => 500,
			// 10x lo vigente y el máximo canjeable por pedido.
			'max' => 5000,
			'l'   => 'Puntos para quien invita',
			'h'   => 'Se pagan cuando la primera compra del invitado llega al estado de abajo. Jamás por solo registrarse, y nunca si el invitado es la misma persona que invita (mismo RUT, teléfono o correo). 0 lo apaga.',
			's'   => 'Referidos',
		),
		'akb_rewards_referral_status'          => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Estado que paga a quien invitó',
			'h' => 'El premio se paga cuando la primera compra del invitado ENTRA a este estado. Usa «Completado» si así registras una entrega o retiro. Si después se anula o se reembolsa por completo, el premio se descuenta.',
			's' => 'Referidos',
		),
		'akb_rewards_referral_cookie_days'     => array(
			't'   => 'int',
			'd'   => 30,
			// No es umbral del absurdo: es el rango que
			// Options::referral_cookie_days() YA impone y que la ayuda del
			// campo ya promete. El panel aceptaba 0 o 9999 y mostraba un
			// valor que el motor ignoraba.
			'min' => 1,
			'max' => 90,
			'l'   => 'Días de memoria del enlace',
			'h'   => 'Cuántos días vale la visita con el enlace antes de crear la cuenta (1-90).',
			's'   => 'Referidos',
		),
		'akb_rewards_referral_max_per_user'    => array(
			't'   => 'int',
			'd'   => 0,
			// 10x el valor VIVO en producción (10).
			'max' => 100,
			'l'   => 'Tope de premios por padrino',
			'h'   => '0 = sin tope. Cuentan los premios que conserva: si el pedido de un invitado se anula y el premio se descuenta, ese cupo vuelve a quedar libre.',
			's'   => 'Referidos',
		),
		'akb_rewards_birthday_enabled' => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Dar regalo de cumpleaños',
			'h' => 'Enciéndelo o apágalo y guarda los ajustes: el cambio se aplica al guardar en la tienda, los correos y Mi cuenta. Los montos guardados se conservan para cuando lo vuelvas a encender.',
			's' => 'Cumpleaños',
		),
		'akb_rewards_birthday_points'          => array(
			't'   => 'int',
			'd'   => 1000,
			// 10x lo vigente.
			'max' => 10000,
			'l'   => 'Regalo de cumpleaños',
			'h'   => 'Puntos una vez al año, con el día y mes que el cliente registre en Mi cuenta. 0 lo apaga.',
			's'   => 'Cumpleaños',
		),
		'akb_rewards_carousel_enabled'         => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Carrusel del home',
			'h' => 'La sección de reseñas de la portada.',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_disclosure_enabled' => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Mostrar también en el carrusel el aviso de puntos',
			'h' => 'Si el aviso de puntos está activado en Reseñas, lo muestra también bajo las reseñas premiadas de la portada. Apagar esta casilla sólo lo oculta en el carrusel: conserva el aviso en las fichas y las etiquetas de reseñas transcritas.',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_min'             => array(
			't'   => 'int',
			'min' => 1,
			'd'   => 8,
			// 10x lo vigente. Un umbral alto deja la sección apagada para
			// siempre sin decirlo; para apagarla está el interruptor.
			'max' => 80,
			'l'   => 'Mínimo de reseñas para aparecer',
			'h'   => 'Se cuenta después de aplicar el tipo de reseña elegido. Bajo este mínimo, la sección no aparece. Usa 1 para mostrarla desde la primera reseña elegible.',
			's'   => 'Carrusel del home',
		),
		'akb_rewards_carousel_media'           => array(
			't'       => 'select',
			'd'       => 'mixed',
			'l'       => 'Reseñas que se muestran',
			'h'       => 'Se muestran primero las más recientes aprobadas. «Con foto» se refiere a una foto adjunta a la reseña; la portada del producto no cuenta como foto del cliente. Publicar una reseña aquí no le otorga puntos.',
			'choices' => array(
				'mixed'  => 'Mixto: con y sin fotos',
				'photos' => 'Sólo reseñas con foto',
				'text'   => 'Sólo reseñas sin foto',
			),
			's'       => 'Carrusel del home',
		),
		'akb_rewards_carousel_min_chars'       => array(
			't'   => 'int',
			'min' => 1,
			'd'   => 40,
			// 10x lo vigente. 400 caracteres es un ensayo: deja el carrusel
			// vacío.
			'max' => 400,
			'l'   => 'Largo mínimo del texto',
			'h'   => 'Caracteres mínimos para que una reseña sea elegible.',
			's'   => 'Carrusel del home',
		),
		'akb_rewards_carousel_cards'           => array(
			't'   => 'int',
			'min' => 1,
			'max' => 20,
			'd'   => 8,
			'l' => 'Tarjetas máximas',
			'h' => 'Cuántas reseñas se pintan (1-20).',
			's' => 'Carrusel del home',
		),
		'akb_rewards_carousel_title'           => array(
			't'     => 'text',
			'd'     => 'Voces del Distrito',
			// El encabezado admite 60 caracteres; se limita en el formulario y
			// en el guardado, que informa si hubo recorte.
			'largo' => 60,
			// Comparte el título con la sección de reseñas de cada ficha.
			'l'     => 'Título de las reseñas (portada y fichas)',
			'h'     => 'Corto: es el encabezado de la sección de reseñas de cada ficha de producto y el título del carrusel de reseñas de la portada. El carrusel sólo sale en la portada cuando hay al menos tantas reseñas como pide «Mínimo de reseñas para aparecer», más arriba. Vacío = «Voces del Distrito».',
			's'     => 'Carrusel del home',
		),
		'akb_rewards_carousel_exclude_rewarded' => array(
			't' => 'yesno',
			'd' => 'no',
			'l' => 'Excluir reseñas premiadas con puntos',
			'h' => 'Por defecto entran (tú ya las curaste al aprobarlas).',
			's' => 'Carrusel del home',
		),
		'akb_rewards_pdp_notice_enabled'       => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Aviso «Gana N puntos» en la ficha',
			// PdpNotice usa el formulario de compra y, si no existe, el resumen.
			'h' => 'Bajo el botón de compra; en un tomo agotado, que no tiene botón, bajo el precio. Con la matemática real del programa.',
			's' => 'Ficha de producto',
		),
		'akb_rewards_alerts_enabled'           => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Avisarme cuando algo necesite revisión',
			'h' => 'Un correo cuando algo del programa no se arregla solo: un canje cobrado sin saldo suficiente, una devolución de puntos que quedó corta, un reembolso borrado, una reseña aprobada que no acreditó, o un correo al cliente que no salió tras tres intentos. Cada aviso dice qué hacer y trae el enlace directo. El mismo aviso no se repite en el día.',
			's' => 'Alertas',
		),
		'akb_rewards_alerts_email'             => array(
			't' => 'recipients',
			'd' => '',
			'l' => 'Correos que reciben las alertas',
			'h' => 'Separados por coma. Vacío = el correo de administración del sitio.',
			's' => 'Alertas',
		),
	);

	/**
	 * Correos de la tienda para elegir: id → título, tal como los lista
	 * WooCommerce → Ajustes → Correos electrónicos.
	 *
	 * @return array<string,string>
	 */
	public static function email_choices(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) ) {
			return array();
		}

		$lista = array();
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || empty( $email->id ) ) {
				continue;
			}
			$titulo                        = isset( $email->title ) ? trim( (string) $email->title ) : '';
			$lista[ (string) $email->id ] = '' !== $titulo ? $titulo : (string) $email->id;
		}

		return $lista;
	}

	/**
	 * Los nombres de option del catálogo. Los consume la migración de
	 * autoload de Schema: una option nueva acá entra sola.
	 *
	 * @return array<int,string>
	 */
	public static function option_names(): array {
		return array_keys( self::FIELDS );
	}

	/**
	 * Cuelga menú y guardado.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Load editing feedback only on this settings screen.
	 *
	 * @param string $hook Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'akb-rewards-admin-settings', Assets::url( 'assets/js/admin-settings.js' ), array(), Assets::version( 'assets/js/admin-settings.js' ), true );
	}

	/**
	 * Submenú bajo WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Puntos y Reseñas (Akibara)',
			'Puntos y Reseñas',
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Pantalla: secciones con sus campos, del catálogo único.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>Puntos y Reseñas</h1>';

		// Los rechazos del último guardado, antes del formulario: si una
		// baranda revierte un campo sin que se vea, es peor que no tenerla. Y
		// con un rechazo en pantalla el aviso verde cambia de frase: el resto
		// del formulario sí se guardó, pero «Ajustes guardados.» a secas debajo
		// de un «No se guardó…» era una contradicción (refutación 2026-09-12).
		$hubo_rechazo = false;
		$avisos       = get_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
		if ( is_array( $avisos ) ) {
			delete_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
			foreach ( $avisos as $aviso ) {
				if ( ! is_array( $aviso ) || '' === (string) ( $aviso['texto'] ?? '' ) ) {
					continue;
				}
				$hubo_rechazo = $hubo_rechazo || 'error' === ( $aviso['tipo'] ?? '' );
				// Por CONCATENACIÓN, no con printf: estos textos llevan
				// porcentajes y montos que escribe el programa, y la regla de
				// la casa mantiene los copys del dueño lejos de cualquier
				// formateador con «%».
				echo '<div class="notice notice-' . esc_attr( 'error' === ( $aviso['tipo'] ?? '' ) ? 'error' : 'warning' ) . '"><p>'
					. esc_html( (string) $aviso['texto'] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['akb_rw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			echo '<div class="notice notice-success"><p>' . ( $hubo_rechazo ? 'Se guardó el resto de los ajustes.' : 'Ajustes guardados.' ) . '</p></div>';
		}
		( new AdminOverview() )->render();
		echo '<p class="description">Configura aquí los montos y las opciones del programa. Los cambios se aplican al guardar; las validaciones de seguridad siguen vigentes. Los cortes de pedidos por país se configuran en Productos → Atributos → País, y por editorial en Productos → Marcas.</p>';
		echo '<nav aria-label="Secciones de ajustes"><p>Ir a: ';
		$links = array();
		foreach ( array_unique( array_column( self::FIELDS, 's' ) ) as $section ) {
			$links[] = '<a href="#' . esc_attr( self::section_anchor( $section ) ) . '">' . esc_html( $section ) . '</a>';
		}
		echo implode( ' · ', $links ) . '</p></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped individually.

		echo '<form data-akb-rewards-settings method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		submit_button( 'Guardar ajustes', 'primary', 'submit-top' );
		echo '<p data-akb-settings-status role="status" aria-live="polite">Los cambios se aplican al guardar.</p>';

		$seccion_abierta = '';
		foreach ( self::FIELDS as $option => $campo ) {
			if ( $campo['s'] !== $seccion_abierta ) {
				if ( '' !== $seccion_abierta ) {
					echo '</table>';
				}
				printf( '<h2 id="%s">%s</h2><table class="form-table" role="presentation">', esc_attr( self::section_anchor( $campo['s'] ) ), esc_html( $campo['s'] ) );
				$seccion_abierta = $campo['s'];
			}

			$valor = get_option( $option, 'akb_rewards_invite_pickup_delay_days' === $option ? \Akibara\Rewards\Reviews\Invitations::pickup_delay_days() : $campo['d'] );

			printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( $option ), esc_html( $campo['l'] ) );

			if ( 'yesno' === $campo['t'] ) {
				printf(
					'<label><input type="checkbox" name="%1$s" id="%1$s" value="yes"%2$s> Activado</label>',
					esc_attr( $option ),
					checked( 'yes', (string) $valor, false )
				);
			} elseif ( 'select' === $campo['t'] ) {
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
				foreach ( $campo['choices'] as $clave => $etiqueta ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $clave ), selected( (string) $valor, $clave, false ), esc_html( $etiqueta ) );
				}
				echo '</select>';
			} elseif ( 'status' === $campo['t'] ) {
				// Selector de estados REALES de pedido (incluye estados custom
				// si algún día existen). Las claves viajan sin el prefijo wc-,
				// que es como las entrega woocommerce_order_status_changed.
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
				$estados = self::order_status_choices();
				// Un estado guardado que la tienda hoy no registra (el plugin que
				// lo aporta, apagado) se muestra en vez de perderse: sin esta
				// opción el navegador enviaba la primera de la lista y el guardado
				// movía la acreditación en silencio, sin llegar nunca a la rama que
				// conserva lo guardado y avisa. Lo mismo hace la lista de correos.
				if ( '' !== (string) $valor && ! array_key_exists( (string) $valor, $estados ) ) {
					$estados[ (string) $valor ] = (string) $valor . ' (este estado ya no está disponible)';
				}
				foreach ( $estados as $clave => $etiqueta ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $clave ),
						selected( (string) $valor, $clave, false ),
						esc_html( $etiqueta )
					);
				}
				echo '</select>';
			} elseif ( 'int' === $campo['t'] ) {
				printf(
					'<input type="number" name="%1$s" id="%1$s" value="%2$s" min="%3$s"%4$s step="1" class="small-text">',
					esc_attr( $option ),
					esc_attr( (string) (int) $valor ),
					esc_attr( (string) (int) ( $campo['min'] ?? 0 ) ),
					isset( $campo['max'] ) ? ' max="' . esc_attr( (string) (int) $campo['max'] ) . '"' : ''
				);
			} elseif ( 'emails' === $campo['t'] ) {
				// Casillas con los correos REALES de la tienda: nadie tiene que
				// saber que «Pedido completado» se llama customer_completed_order.
				$marcados = array_filter( array_map( 'trim', explode( ',', (string) $valor ) ) );
				$opciones = self::email_choices();
				// Un correo guardado que la tienda ya no registra (un plugin
				// apagado) se muestra en vez de borrarse en silencio al guardar.
				foreach ( $marcados as $guardado ) {
					if ( ! isset( $opciones[ $guardado ] ) ) {
						$opciones[ $guardado ] = $guardado . ' (este correo ya no está disponible)';
					}
				}
				// El vacío viaja siempre: dice «el formulario llegó», para no
				// confundir «ninguna casilla» con «no se pintó la lista».
				printf( '<fieldset><input type="hidden" name="%s[]" value="">', esc_attr( $option ) );
				foreach ( $opciones as $id => $titulo ) {
					printf(
						'<label style="display:block;margin:0 0 4px"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
						esc_attr( $option ),
						esc_attr( $id ),
						checked( in_array( $id, $marcados, true ), true, false ),
						esc_html( $titulo )
					);
				}
				echo '</fieldset>';
			} elseif ( 'recipients' === $campo['t'] ) {
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" inputmode="email">',
					esc_attr( $option ),
					esc_attr( (string) $valor ),
					esc_attr( (string) get_option( 'admin_email', '' ) )
				);
			} else {
				// La fecha de vigencia lleva patrón y placeholder: el navegador
				// frena el formato incorrecto antes de que el servidor lo rechace.
				$extra = 'akb_rewards_program_start' === $option
					? ' placeholder="AAAA-MM-DD HH:MM:SS" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" inputmode="numeric"'
					: '';
				// El tope de largo de la fila, en las dos capas a la vez, como el
				// `max` de los enteros: el navegador no deja escribir más y el
				// guardado recorta lo que llegue por otra vía.
				if ( isset( $campo['largo'] ) ) {
					$extra .= ' maxlength="' . (int) $campo['largo'] . '"';
				}
				printf(
					'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text"%3$s>',
					esc_attr( $option ),
					esc_attr( (string) $valor ),
					$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literales fijos de la casa y un entero, sin datos de usuario.
				);
			}

			if ( '' !== $campo['h'] ) {
				// El tope se dice con el MISMO número que aplica el guardado.
				printf(
					'<p class="description">%s</p>',
					esc_html( $campo['h'] . ( isset( $campo['largo'] ) ? ' Hasta ' . (int) $campo['largo'] . ' caracteres.' : '' ) )
				);

				// Explica si el umbral de reseñas permite mostrar el carrusel.
				if ( 'akb_rewards_carousel_title' === $option ) {
					$encendido = Options::carousel_on();
					$evidencia = self::carousel_evidence_text(
						$encendido,
						$encendido ? ( new \Akibara\Rewards\Reviews\Carousel() )->eligible_seen() : 0,
						Options::carousel_min()
					);
					if ( '' !== $evidencia ) {
						printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
					}
				}

				// Contrasta el estado elegido con la actividad real de los pedidos.
				if ( 'status' === (string) $campo['t'] ) {
					$evidencia = self::status_evidence_text( self::status_counts_90d( (string) $valor ), (string) $valor, self::order_status_choices(), $option );
					if ( '' !== $evidencia ) {
						printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
					}
				}

				// Advierte en ambos campos si el aviso está activo sin correos elegidos.
				if ( in_array( $option, array( 'akb_rewards_review_nudge_enabled', 'akb_rewards_nudge_email_ids' ), true )
					&& 'yes' === (string) get_option( 'akb_rewards_review_nudge_enabled', 'yes' )
					&& array() === array_filter( array_map( 'trim', explode( ',', (string) get_option( 'akb_rewards_nudge_email_ids', 'customer_completed_order' ) ) ) ) ) {
					printf(
						'<p class="description" style="color:#996800"><strong>%s</strong></p>',
						esc_html( 'Ojo: está activado, pero no hay ningún correo marcado abajo, así que el párrafo no sale en ninguno. Marca al menos uno, o apaga el interruptor.' )
					);
				}

				// Explica la fecha futura o ilegible que suspende la acumulación.
				if ( 'akb_rewards_program_start' === $option ) {
					$estado_programa = \Akibara\Rewards\Points\Accrual::program_state();
					if ( \Akibara\Rewards\Points\Accrual::PROGRAMA_AGENDADO === $estado_programa ) {
						printf(
							'<p class="description" style="color:#996800"><strong>%s</strong></p>',
							esc_html( sprintf( 'Ojo: el programa empieza el %s. Hasta entonces nadie acumula puntos por comprar, y la tienda no los promete en la ficha, en Mi cuenta ni en la caja.', \Akibara\Rewards\Points\ProgramPage::start_label() ) )
						);
					} elseif ( \Akibara\Rewards\Points\Accrual::PROGRAMA_ILEGIBLE === $estado_programa ) {
						printf(
							'<p class="description" style="color:#b32d2e"><strong>%s</strong></p>',
							esc_html( 'La fecha guardada no se puede leer, así que HOY nadie está acumulando puntos y la tienda dejó de prometerlos. Escríbela de nuevo con el formato AAAA-MM-DD HH:MM:SS y guarda.' )
						);
					}
				}

				// Muestra el estado del aviso visible junto al filtro de JSON-LD.
				if ( 'akb_rewards_schema_exclude_rewarded' === $option
					&& 'yes' !== (string) get_option( 'akb_rewards_disclosure_enabled', 'no' ) ) {
					printf(
						'<p class="description" style="color:#996800"><strong>%s</strong></p>',
						esc_html( 'Ojo: hoy las reseñas premiadas se leen en la ficha SIN aviso de que ganaron puntos. El aviso se enciende en el interruptor de arriba.' )
					);
				}

				// Expone al dueño cuántos clientes pidieron dejar de recibir invitaciones.
				if ( 'akb_rewards_invite_enabled' === $option ) {
					$bajas = \Akibara\Rewards\Reviews\InviteOptOut::total();
					printf(
						'<p class="description">%s</p>',
						esc_html(
							0 === $bajas
								? 'Cada invitación lleva su propio enlace para que el cliente pida no recibir más. Nadie lo ha usado todavía.'
								: sprintf(
									1 === $bajas
										? '%s cliente pidió no recibir más invitaciones: a esa dirección ya no se le escribe.'
										: '%s clientes pidieron no recibir más invitaciones: a esas direcciones ya no se les escribe.',
									number_format_i18n( $bajas )
								)
						)
					);
				}
			}
			echo '</td></tr>';
		}
		echo '</table>';

		submit_button( 'Guardar ajustes' );
		echo '</form>';

		/**
		 * Bloques extra de la pantalla de ajustes (ajuste manual de puntos,
		 * futuros paneles). Dentro del .wrap, fuera del form de settings.
		 *
		 * @since 0.3.0
		 */
		do_action( 'akb_rewards_settings_after' );

		echo '</div>';
	}

	/**
	 * Guardado: sanea por tipo desde el MISMO catálogo.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en la línea de arriba.
		$this->save_fields( (array) wp_unslash( $_POST ) );

		// Invalida el transient del carrusel y el HTML de portada tras guardar.
		( new \Akibara\Rewards\Reviews\Carousel() )->flush();

		wp_safe_redirect( add_query_arg( 'akb_rw_saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Núcleo del guardado: sanea por tipo desde el MISMO catálogo, aplica las
	 * barandas que miran a VARIOS campos a la vez, y recién entonces persiste.
	 *
	 * Sanea y valida todos los campos antes de persistirlos en el orden del
	 * catálogo. Las restricciones entre campos reciben la configuración completa.
	 *
	 * Separado del wrapper (capability, nonce, purga, redirect y `exit`) para
	 * poder invocarlo sin terminar la petición.
	 *
	 * @param array<string,mixed> $input Datos del formulario, ya des-escapados.
	 * @return array<string,mixed> Lo que quedó guardado, por option.
	 */
	public function save_fields( array $input ): array {
		$avisos   = array();
		$acotados = array();
		$valores  = $this->sanitize_fields( $input, $avisos, $acotados );

		$valores = $this->guard_giveback( $valores, $avisos );
		$valores = $this->guard_expiry_warning( $valores, $avisos );

		foreach ( $acotados as $option => $acotado ) {
			if ( (int) $valores[ $option ] === $acotado ) {
				$min      = (int) ( self::FIELDS[ $option ]['min'] ?? 0 );
				$max      = (int) self::FIELDS[ $option ]['max'];
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => self::etiqueta( $option ) . ' admite entre ' . number_format_i18n( $min ) . ' y ' . number_format_i18n( $max ) . ': se guardó en ' . number_format_i18n( $acotado ) . '.',
				);
			}
		}

		// Captura las opciones incluidas en el HTML de las fichas para emitir
		// una sola invalidación si cambia cualquiera de ellas.
		$ficha_antes = array();
		foreach ( array_merge( self::PROMESA_EN_CADA_FICHA, self::FORMA_DE_CADA_FICHA ) as $option ) {
			$ficha_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
		}
		// Las opciones del carrusel y el aviso de divulgación gobiernan su
		// contenido cacheado; la lista se obtiene del catálogo del panel.
		$resenas_antes = array(
			'akb_rewards_disclosure_enabled' => (string) get_option( 'akb_rewards_disclosure_enabled', 'no' ),
		);
		foreach ( array_keys( self::FIELDS ) as $option ) {
			if ( 0 === strpos( $option, 'akb_rewards_carousel_' ) ) {
				$resenas_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
			}
		}

		foreach ( array_keys( self::FIELDS ) as $option ) {
			// autoload = true: son escalares diminutos que se leen en el camino
			// del cliente (ficha, Mi cuenta, carrito, correos). Ver
			// Schema::autoloaded_options().
			update_option( $option, $valores[ $option ], true );
		}

		// La purga total ya incluye la del origen: si cambió una promesa y
		// además la forma, basta con ella.
		if ( self::cambio_alguno( self::PROMESA_EN_CADA_FICHA, $ficha_antes, $valores ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		} elseif ( self::cambio_alguno( self::FORMA_DE_CADA_FICHA, $ficha_antes, $valores ) ) {
			do_action( 'litespeed_purge', '*' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed (tercero).
		}

		foreach ( $resenas_antes as $option => $antes ) {
			if ( $antes !== (string) $valores[ $option ] ) {
				// Invalida tanto el transient como el HTML de portada.
				( new \Akibara\Rewards\Reviews\Carousel() )->flush();
				break;
			}
		}

		if ( array() !== $avisos ) {
			set_transient( self::TRANSIENT_AVISOS . get_current_user_id(), $avisos, MINUTE_IN_SECONDS );
		}

		return $valores;
	}

	/**
	 * ¿Cambió en este guardado alguno de estos ajustes?
	 *
	 * @param array<int,string>    $options Ajustes a mirar.
	 * @param array<string,string> $antes   Lo que había, por option.
	 * @param array<string,mixed>  $valores Lo que quedó guardado, por option.
	 * @return bool
	 */
	private static function cambio_alguno( array $options, array $antes, array $valores ): bool {
		foreach ( $options as $option ) {
			if ( $antes[ $option ] !== (string) $valores[ $option ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Primera pasada: el valor de cada campo del catálogo, saneado por tipo.
	 * NO escribe nada.
	 *
	 * @param array<string,mixed>                                $input    Formulario.
	 * @param array<int,array{tipo:string,texto:string}>         $avisos   Avisos para el dueño, por referencia.
	 * @param array<string,int>                                  $acotados Enteros acotados al rango del catálogo, por referencia.
	 * @return array<string,mixed> option => valor saneado.
	 */
	private function sanitize_fields( array $input, array &$avisos, array &$acotados = array() ): array {
		$valores = array();

		foreach ( self::FIELDS as $option => $campo ) {
			if ( 'yesno' === $campo['t'] ) {
				// Un checkbox apagado NO viaja en el POST: su ausencia es el
				// «no». Por eso se recorre el catálogo y no lo recibido.
				$valor = isset( $input[ $option ] ) && 'yes' === $input[ $option ] ? 'yes' : 'no';
			} elseif ( 'select' === $campo['t'] ) {
				$valor = isset( $input[ $option ] ) && is_string( $input[ $option ] ) ? $input[ $option ] : (string) get_option( $option, $campo['d'] );
				if ( ! array_key_exists( $valor, $campo['choices'] ) ) {
					$guardado = (string) get_option( $option, $campo['d'] );
					$valor    = array_key_exists( $guardado, $campo['choices'] ) ? $guardado : $campo['d'];
					$avisos[] = array( 'tipo' => 'warning', 'texto' => '«' . $campo['l'] . '» no contiene una opción válida: se conservó la selección anterior.' );
				}
			} elseif ( 'status' === $campo['t'] ) {
				// Rechaza estados nuevos desconocidos. Conserva un estado ya guardado
				// si falta temporalmente el plugin que lo registra, y avisa al dueño.
				$valor    = sanitize_key( (string) ( $input[ $option ] ?? '' ) );
				$guardado = (string) get_option( $option, (string) $campo['d'] );
				if ( ! array_key_exists( $valor, self::order_status_choices() ) ) {
					if ( '' !== $valor && $valor === $guardado ) {
						// Con la forma que la pantalla lee ({tipo, texto}): como
						// texto suelto, `render()` lo descartaba y el dueño nunca
						// veía por qué el estado no cambió.
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => '«' . $campo['l'] . '» se quedó como estaba (' . $valor . '): ese estado no existe hoy en la tienda, seguramente porque el plugin que lo registra está desactivado.',
						);
					} else {
						$valor = $guardado !== '' && array_key_exists( $guardado, self::order_status_choices() )
							? $guardado
							: (string) $campo['d'];
					}
				}
			} elseif ( 'int' === $campo['t'] ) {
				$crudo = isset( $input[ $option ] ) ? (int) $input[ $option ] : (int) $campo['d'];
				$valor = self::clamp_int( $option, $crudo );
				if ( isset( $input[ $option ] ) && $valor !== $crudo ) {
					$acotados[ $option ] = $valor;
				}
			} elseif ( 'emails' === $campo['t'] ) {
				// Ninguna casilla marcada no viaja en el POST: es «ninguno».
				// Lo marcado se cruza con los correos que la tienda tiene de
				// verdad, en el orden de su lista.
				$valor = '';
				if ( isset( $input[ $option ] ) ) {
					$guardados = array_filter( array_map( 'trim', explode( ',', (string) get_option( $option, $campo['d'] ) ) ) );
					$opciones  = self::email_choices();
					if ( array() === $opciones ) {
						$valor = implode( ',', $guardados ); // Sin la lista no se puede juzgar: se conserva.
					} else {
						// Valen los correos de la tienda y los que ya estaban guardados
						// (el panel los muestra aunque su plugin esté apagado).
						$validos = array_values( array_unique( array_merge( array_keys( $opciones ), $guardados ) ) );
						$valor   = implode( ',', array_values( array_intersect( $validos, array_map( 'strval', (array) $input[ $option ] ) ) ) );
					}
				}
			} elseif ( 'recipients' === $campo['t'] ) {
				$descartados = array();
				$valor       = implode( ', ', Options::email_list( sanitize_text_field( (string) ( $input[ $option ] ?? '' ) ), $descartados ) );
				if ( array() !== $descartados ) {
					$avisos[] = array(
						'tipo'  => 'warning',
						'texto' => 'En «' . $campo['l'] . '» se descartó lo que no es un correo: ' . implode( ', ', $descartados ) . '.',
					);
				}
			} else {
				$valor = isset( $input[ $option ] ) ? sanitize_text_field( (string) $input[ $option ] ) : (string) $campo['d'];
				// Recorta por caracteres visibles sin rechazar el resto del formulario.
				// El aviso concatena el texto del dueño sin interpretarlo como formato.
				if ( isset( $campo['largo'] ) ) {
					$letras = self::letras_visibles( $valor );
					if ( count( $letras ) > (int) $campo['largo'] ) {
						$valor    = rtrim( implode( '', array_slice( $letras, 0, (int) $campo['largo'] ) ) );
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => '«' . $campo['l'] . '» admite hasta ' . (int) $campo['largo'] . ' caracteres: se guardó recortado, como «' . $valor . '».',
						);
					}
				}
				// La fecha de vigencia la lee Accrual::program_allows() con
				// createFromFormat: un formato mal escrito BLOQUEABA toda la
				// acreditación en silencio (QA 2026-09-01). Se rechaza al
				// guardar, se conserva la vigente y se avisa en pantalla.
				if ( 'akb_rewards_program_start' === $option ) {
					// La misma lectura con la que el motor la va a interpretar: una
					// sola función, para que el panel no acepte lo que el motor rechaza.
					if ( null === \Akibara\Rewards\Points\Accrual::parse_program_start( $valor ) ) {
						$valor    = (string) get_option( $option, $campo['d'] );
						$avisos[] = array(
							'tipo'  => 'warning',
							'texto' => 'La fecha de «Inicio de vigencia del programa» no tiene el formato AAAA-MM-DD HH:MM:SS: se conservó la anterior. Con una fecha inválida nadie acreditaría puntos.',
						);
					}
				}
			}

			$valores[ $option ] = $valor;
		}

		return $valores;
	}

	/**
	 * Un texto ya saneado, partido en lo que el cliente ve como UN carácter.
	 *
	 * Las entidades HTML reconocidas se cuentan como un carácter y nunca se
	 * parten. El resto se cuenta por carácter Unicode, no por byte, para
	 * aplicar el límite sobre el texto visible en el formulario.
	 *
	 * @param string $texto Texto saneado, tal como se guardaría.
	 * @return string[]
	 */
	private static function letras_visibles( string $texto ): array {
		$letras = array();
		foreach ( preg_split( '/(&#?[a-z0-9]+;)/i', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array() as $trozo ) {
			if ( '&' === $trozo[0] && html_entity_decode( $trozo, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $trozo ) {
				$letras[] = $trozo;
				continue;
			}
			array_push( $letras, ...mb_str_split( $trozo, 1, 'UTF-8' ) );
		}

		return $letras;
	}

	/**
	 * Acota un entero al rango que declara su fila del catálogo. Es la MISMA
	 * cuenta que hace el navegador con los atributos `min`/`max` del campo:
	 * añadir el dato a la fila enciende las dos capas de una vez, y el
	 * recorrido de PHP defiende del POST hecho con curl o desde las devtools.
	 *
	 * @param string $option Option del catálogo.
	 * @param int    $valor  Valor pretendido.
	 * @return int
	 */
	private static function clamp_int( string $option, int $valor ): int {
		$campo = self::FIELDS[ $option ];
		$valor = max( (int) ( $campo['min'] ?? 0 ), $valor );
		if ( isset( $campo['max'] ) ) {
			$valor = min( (int) $campo['max'], $valor );
		}
		if ( 'akb_rewards_redeem_value' === $option && $valor <= 0 ) {
			$valor = 1; // El motor cae a 1: la pantalla no puede mostrar un 0 que no rige.
		}

		return $valor;
	}

	/**
	 * BARANDA DE LA RAZÓN: cuántos pesos devuelve la tienda por cada 100
	 * gastados. No la fija ningún campo por separado sino los TRES a la vez
	 * (puntos por tramo ÷ tamaño del tramo × pesos por punto), y por eso
	 * ningún `max` por campo la cubre: con el «2» de siempre y un tramo de $1
	 * la devolución es del 200 % sin que ningún número se vea raro.
	 *
	 * El techo entra en la misma baranda como cuarto campo: bajarlo por
	 * debajo de la razón vigente es tan contradictorio como subir la razón por
	 * encima de él, y se conserva el techo que había. La comparación es en
	 * ENTEROS (Calculator::giveback_exceeds): en float, el 15 % clavado a veces
	 * «superaba» al 15.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @return array<string,mixed>
	 */
	private function guard_giveback( array $valores, array &$avisos ): array {
		$campos = array( 'akb_rewards_earn_points', 'akb_rewards_earn_amount', 'akb_rewards_redeem_value', 'akb_rewards_max_giveback_pct' );

		return $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				return Calculator::giveback_exceeds(
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount'],
					(int) $v['akb_rewards_redeem_value'],
					(int) $v['akb_rewards_max_giveback_pct']
				);
			},
			static function ( array $v ): string {
				$puntos = Calculator::points_for_base(
					(float) self::EJEMPLO_COMPRA,
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount']
				);
				$pct = Calculator::giveback_pct(
					(int) $v['akb_rewards_earn_points'],
					(int) $v['akb_rewards_earn_amount'],
					(int) $v['akb_rewards_redeem_value']
				);

				return str_replace(
					array( '{compra}', '{puntos}', '{pesos}', '{pct}', '{techo}' ),
					array(
						self::pesos_texto( (float) self::EJEMPLO_COMPRA ),
						number_format_i18n( $puntos ),
						self::pesos_texto( (float) $puntos * (int) $v['akb_rewards_redeem_value'] ),
						self::pct_texto( $pct ),
						self::pct_texto( (float) (int) $v['akb_rewards_max_giveback_pct'] ),
					),
					'Con ese valor, una compra de {compra} devolvería {puntos} puntos, que valen {pesos} de descuento: el {pct} de la compra, sobre el techo de {techo} del programa.'
				);
			},
			'akb_rewards_earn_enabled'
		);
	}

	/**
	 * BARANDA DE COHERENCIA DEL VENCIMIENTO: no se puede avisar con más
	 * anticipación que la vida de los puntos.
	 *
	 * `Expiry::expiring_soon()` mira una ventana de N días: si N cubre la vida
	 * entera, el correo «tienes puntos por vencer» sale el día mismo en que se
	 * ganan.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @return array<string,mixed>
	 */
	private function guard_expiry_warning( array $valores, array &$avisos ): array {
		$campos = array( 'akb_rewards_expiry_warn_days', 'akb_rewards_expiry_months' );

		return $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				$meses = (int) $v['akb_rewards_expiry_months'];
				$dias  = (int) $v['akb_rewards_expiry_warn_days'];

				// 0 meses = no vencen jamás, y entonces el aviso no rige.
				return $meses > 0 && $dias > 0 && $dias > $meses * 30;
			},
			static function ( array $v ): string {
				return str_replace(
					array( '{dias}', '{meses}', '{vida}' ),
					array(
						number_format_i18n( (int) $v['akb_rewards_expiry_warn_days'] ),
						number_format_i18n( (int) $v['akb_rewards_expiry_months'] ),
						number_format_i18n( (int) $v['akb_rewards_expiry_months'] * 30 ),
					),
					'Avisar con {dias} días de anticipación cuando los puntos viven {meses} meses (unos {vida} días) manda el correo «tienes puntos por vencer» el mismo día en que el cliente los gana.'
				);
			},
			'akb_rewards_expiry_enabled'
		);
	}

	/**
	 * El mecanismo común de las dos barandas: si el conjunto de campos viola
	 * la regla, se REVIERTEN sólo los que cambiaron en este envío y el resto
	 * del formulario se guarda normal.
	 *
	 * Revertir sólo los cambiados es lo que hace que el aviso diga la verdad
	 * («no se guardó ESTO») en vez de deshacer trabajo que el dueño no tocó. Y
	 * si NINGUNO cambió —valor heredado escrito por WP-CLI o SQL, o una
	 * instalación anterior a la baranda— se guarda igual y sólo se advierte:
	 * si no, un valor heredado fuera de rango bloquearía para siempre
	 * cualquier otro guardado del panel, que es peor que el problema.
	 *
	 * Con el interruptor del carril APAGADO la regla no rige hoy (el accessor
	 * devuelve 0 y nadie acumula ni vence), así que tampoco se revierte: se
	 * guarda y se advierte, diciendo que no rige y que hay que corregirlo
	 * antes de encender. Rechazar ahí era afirmar una consecuencia que no
	 * ocurre (refutación 2026-09-12).
	 *
	 * @param array<string,mixed>                        $valores      Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos       Avisos, por referencia.
	 * @param array<int,string>                          $involucrados Options que forman la regla.
	 * @param callable                                   $viola        fn(array):bool — ¿se rompe la regla?
	 * @param callable                                   $diagnostico  fn(array):string — la consecuencia, en pesos.
	 * @param string                                     $carril       Option yes/no del interruptor que hace regir la regla.
	 * @return array<string,mixed>
	 */
	private function aplicar_baranda( array $valores, array &$avisos, array $involucrados, callable $viola, callable $diagnostico, string $carril ): array {
		if ( ! $viola( $valores ) ) {
			return $valores;
		}

		$diagnostico_intentado = (string) $diagnostico( $valores );

		if ( 'yes' !== (string) ( $valores[ $carril ] ?? 'yes' ) ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => 'Ojo: ' . $diagnostico_intentado . ' Hoy no rige, porque ' . self::etiqueta( $carril ) . ' está apagado; corrige ' . self::lista_etiquetas( $involucrados, 'o' ) . ' antes de encenderlo.',
			);

			return $valores;
		}

		$previos   = array();
		$cambiados = array();
		foreach ( $involucrados as $option ) {
			$previos[ $option ] = self::clamp_int( $option, (int) get_option( $option, self::FIELDS[ $option ]['d'] ) );
			if ( $previos[ $option ] !== (int) $valores[ $option ] ) {
				$cambiados[] = $option;
			}
		}

		if ( array() === $cambiados ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => $diagnostico_intentado . ' Este guardado no cambió esos campos, así que quedaron como estaban. Corrige ' . self::lista_etiquetas( $involucrados, 'o' ) . '.',
			);

			return $valores;
		}

		$conservado = array();
		foreach ( $cambiados as $option ) {
			$valores[ $option ] = $previos[ $option ];
			$conservado[]       = self::etiqueta( $option ) . ' en ' . number_format_i18n( $previos[ $option ] );
		}

		$texto = 'No se guardó ' . self::lista_etiquetas( $cambiados, 'ni' ) . '. '
			. $diagnostico_intentado
			. ' Se conservó lo que había: ' . implode( ', ', $conservado ) . '.';

		if ( $viola( $valores ) ) {
			$texto .= ' Ojo: lo que quedó guardado sigue teniendo el mismo problema.';
		}

		$avisos[] = array(
			'tipo'  => 'error',
			'texto' => $texto,
		);

		return $valores;
	}

	/**
	 * La etiqueta que el dueño ve en pantalla, entrecomillada.
	 *
	 * @param string $option Option del catálogo.
	 * @return string
	 */
	private static function etiqueta( string $option ): string {
		return '«' . self::FIELDS[ $option ]['l'] . '»';
	}

	/**
	 * Enumeración legible de etiquetas: «A», «B» y/o «C».
	 *
	 * @param array<int,string> $options Options del catálogo.
	 * @param string            $nexo    Conector final («o», «ni», «y»).
	 * @return string
	 */
	private static function lista_etiquetas( array $options, string $nexo ): string {
		$etiquetas = array_map( array( self::class, 'etiqueta' ), array_values( $options ) );
		if ( count( $etiquetas ) < 2 ) {
			return (string) ( $etiquetas[0] ?? '' );
		}

		$ultima = array_pop( $etiquetas );

		return implode( ', ', $etiquetas ) . ' ' . $nexo . ' ' . $ultima;
	}

	/**
	 * Pesos con separador de miles, para los avisos.
	 *
	 * @param float $monto Monto.
	 * @return string
	 */
	private static function pesos_texto( float $monto ): string {
		return '$' . number_format_i18n( round( $monto ) );
	}

	/**
	 * Porcentaje para los avisos. Se arma por CONCATENACIÓN, jamás con
	 * sprintf: un `%` en un texto que lee el dueño es un fatal esperando (y la
	 * regla de la casa lo prohíbe).
	 *
	 * @param float $pct Porcentaje.
	 * @return string
	 */
	private static function pct_texto( float $pct ): string {
		$redondeado = round( $pct, 1 );
		$decimales  = (float) (int) $redondeado === $redondeado ? 0 : 1;

		return number_format_i18n( $redondeado, $decimales ) . '%';
	}

	/**
	 * Estados de pedido elegibles para disparar la invitación, SIN el
	 * prefijo `wc-` (así los entrega `woocommerce_order_status_changed`,
	 * que es contra lo que compara Invitations::trigger_status()).
	 *
	 * Con WooCommerce vivo la lista es la REAL del sitio (estados custom
	 * incluidos); sin ella (suite), el trío estándar para que la pantalla
	 * no quede vacía ni el sanitizado deje pasar cualquier cosa.
	 *
	 * @return array<string,string> clave => etiqueta.
	 */
	public static function order_status_choices(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$choices = array();
			foreach ( wc_get_order_statuses() as $clave => $etiqueta ) {
				$clave = str_starts_with( (string) $clave, 'wc-' ) ? substr( (string) $clave, 3 ) : (string) $clave;
				// Estados terminales-negativos fuera: invitar a reseñar un
				// pedido cancelado/reembolsado/fallido no tiene sentido.
				if ( in_array( $clave, array( 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) {
					continue;
				}
				$choices[ $clave ] = (string) $etiqueta;
			}

			if ( array() !== $choices ) {
				return $choices;
			}
		}

		return array(
			'processing' => 'Procesando',
			'completed'  => 'Completado',
			'on-hold'    => 'En espera',
		);
	}

	/**
	 * Cuántos pedidos están HOY en cada estado pagado —más el elegido, si es
	 * otro (p. ej. «En espera»)— de los creados en los últimos 90 días. Tres o
	 * cuatro consultas ligeras, sólo en la pantalla del panel.
	 *
	 * @param string $current El estado elegido en el campo.
	 * @return array<string, int> estado => pedidos.
	 */
	private static function status_counts_90d( string $current = '' ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$estados = OrderStates::PAID;
		if ( '' !== $current && ! in_array( $current, $estados, true ) ) {
			$estados[] = $current;
		}

		$desde = gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS );
		$out   = array();
		foreach ( $estados as $estado ) {
			$resultado      = wc_get_orders(
				array(
					'status'       => $estado,
					'date_created' => '>=' . $desde,
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
				)
			);
			$out[ $estado ] = is_object( $resultado ) && isset( $resultado->total ) ? (int) $resultado->total : 0;
		}

		return $out;
	}

	/** Stable destinations for the overview and settings navigation. */
	private static function section_anchor( string $section ): string {
		$anchors = array( 'Reseñas' => 'reviews', 'Carrusel del home' => 'carousel', 'Puntos por compra' => 'points' );
		return 'akb-rewards-section-' . ( $anchors[ $section ] ?? md5( $section ) );
	}

	/**
	 * Describe current order counts without inferring their transition history.
	 *
	 * @param array<string, int>    $counts  estado => pedidos (90 días).
	 * @param string                $current Estado elegido.
	 * @param array<string, string> $labels  estado => etiqueta.
	 * @param string                $option Campo de estado que muestra el aviso.
	 * @return string '' si no hay datos.
	 */
	public static function status_evidence_text( array $counts, string $current, array $labels, string $option = 'akb_rewards_invite_status' ): string {
		if ( array() === $counts || array_sum( $counts ) <= 0 ) {
			return '';
		}

		$partes = array();
		foreach ( $counts as $estado => $n ) {
			$partes[] = ( $labels[ $estado ] ?? $estado ) . ' ' . number_format_i18n( $n );
		}
		$texto = 'Pedidos de los últimos 90 días, por estado actual: ' . implode( ' · ', $partes ) . '.';

		// El estado más frecuente hoy no demuestra por qué estados pasó cada
		// pedido ni cuál será su estado final.
		$terminal = '';
		$maximo   = 0;
		foreach ( OrderStates::PAID as $estado ) {
			if ( (int) ( $counts[ $estado ] ?? 0 ) > $maximo ) {
				$maximo   = (int) $counts[ $estado ];
				$terminal = $estado;
			}
		}

		$actual = (int) ( $counts[ $current ] ?? 0 );
		if ( '' !== $terminal && $terminal !== $current && $actual < $maximo && OrderStates::is_at_or_after( $current, $terminal ) ) {
			$nombre_actual   = $labels[ $current ] ?? $current;
			$nombre_terminal = $labels[ $terminal ] ?? $terminal;
			$contextos = array(
				'akb_rewards_invite_status'   => 'las invitaciones',
				'akb_rewards_invite_pickup_status' => 'las invitaciones de entrega directa o retiro',
				'akb_rewards_referral_status' => 'los premios de referidos',
			);
			$contexto = $contextos[ $option ] ?? 'la acreditación de puntos';
			$texto   .= ' Ojo: hoy hay ' . number_format_i18n( $actual ) . ' pedidos en «' . $nombre_actual . '» y ' . number_format_i18n( $maximo ) . ' en «' . $nombre_terminal . '». Revisa que el estado elegido para ' . $contexto . ' coincida con tu flujo. Estos conteos no indican por qué estados pasó cada pedido ni prueban que el proceso haya fallado.';
		}

		return $texto;
	}

	/**
	 * Función pura: bajo el título de las reseñas, si HOY la portada muestra
	 * el carrusel que la etiqueta nombra. Lo que se promete contra lo que se
	 * ve: con menos reseñas elegibles que el mínimo, el carrusel no se pinta y
	 * ese título sólo se lee en las fichas.
	 *
	 * @param bool $encendido ¿Está encendido el carrusel?
	 * @param int  $elegibles Reseñas elegibles que ve el carrusel (topadas en el umbral).
	 * @param int  $minimo    Mínimo de reseñas para aparecer.
	 * @return string '' cuando el carrusel sí sale.
	 */
	public static function carousel_evidence_text( bool $encendido, int $elegibles, int $minimo ): string {
		if ( ! $encendido ) {
			return 'Hoy el carrusel de la portada está apagado (casilla «Carrusel del home», más arriba): este título sólo se ve en las fichas.';
		}
		if ( $elegibles >= $minimo ) {
			return '';
		}

		if ( $elegibles <= 0 ) {
			$cuantas = 'ninguna reseña cumple';
		} elseif ( 1 === $elegibles ) {
			$cuantas = 'sólo 1 reseña cumple';
		} else {
			$cuantas = 'sólo ' . number_format_i18n( $elegibles ) . ' reseñas cumplen';
		}

		return 'Hoy el carrusel no sale en la portada: ' . $cuantas . ' las condiciones de esta sección y el mínimo es ' . number_format_i18n( $minimo ) . '. Mientras tanto, este título sólo se ve en las fichas.';
	}
}
