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
use Akibara\Rewards\Points\Tiers;
use Akibara\Rewards\Reviews\AdminOverview;
use Akibara\Rewards\Reviews\InvitationReschedule;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress InvalidArgument
 * @psalm-suppress InvalidOperand
 * @psalm-suppress RedundantCast
 * @psalm-suppress TypeDoesNotContainType
 */
// @phpstan-ignore complexity.classLike
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
		'akb_rewards_tiers_enabled',
		'akb_rewards_tier_coleccionista_min',
		'akb_rewards_tier_coleccionista_mult',
		'akb_rewards_tier_residente_min',
		'akb_rewards_tier_residente_mult',
		'akb_rewards_tier_validity_days',
	);

	/**
	 * Catálogo de campos: option → [tipo, default, etiqueta, ayuda, sección].
	 * ÚNICA fuente de la pantalla y del guardado — añadir una option nueva es
	 * añadir UNA fila acá. Opcionales: `min`/`max`, `step`, `suffix`, `hidden`,
	 * `choices` y `largo` de un texto.
	 *
	 * @var array<string,array{t:string,d:mixed,l:string,h:string,s:string,min?:int|float,max?:int|float,step?:string,suffix?:string,hidden?:bool,choices?:array<string,string>,largo?:int}>
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
			'l'   => 'Puntos otorgados por compra',
			'h'   => 'Puntos ganados por cada tramo gastado (ej. 2 puntos por cada $100 gastados = 2% de retorno base en compras). Los niveles Coleccionista y Residente de Akiba multiplican esta tasa según lo configurado más abajo.',
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
			'h'   => 'Monto en pesos que compone cada tramo de acumulación (por defecto: $100 CLP). Con $100, cada $10.000 de compra acumulan 200 puntos. 0 apaga los puntos por compra.',
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
			'h'   => '1 = un peso chileno por punto ($1 CLP por punto canjeado, IVA incluido).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_max_giveback_pct'         => array(
			't'      => 'int',
			'd'      => \Akibara\Rewards\Support\Options::DEFAULT_MAX_GIVEBACK_PCT,
			'min'    => 1,
			'max'    => \Akibara\Rewards\Support\Options::HARD_MAX_GIVEBACK_PCT,
			'l'      => 'Techo de devolución del programa (%)',
			'h'      => 'Guardrail interno automático del 15%: protege el margen de la tienda evitando combinaciones erradas de acumulación.',
			's'      => 'Puntos por compra',
			'hidden' => true,
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
			'l' => 'Anti-fraude: Validar cliente único por RUT',
			'h' => 'Impide multicuentas: si el RUT del comprador ya registra una compra en la tienda, no se otorga nuevo bono de bienvenida ni premio a quien invitó.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_phone'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Anti-fraude: Validar cliente único por Teléfono',
			'h' => 'Verifica el número celular para evitar que un mismo cliente use múltiples cuentas desde el mismo teléfono.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_first_purchase_by_email'  => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Anti-fraude: Validar cliente único por Correo',
			'h' => 'Verifica el correo del pedido contra el historial de compras del programa.',
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
			'h' => 'Los puntos se pagan cuando el pedido llega a este estado o a uno posterior. «Procesando» = la pasarela confirmó el pago, así el cliente ve sus puntos apenas paga; «Completado» espera a que el pedido se despache o entregue. Si la venta se cae (cancelado, fallido o reembolsado), los puntos se retiran solos.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_accrual_escrow_days'      => array(
			't'   => 'int',
			'min' => 0,
			'max' => 60,
			'd'   => Options::DEFAULT_ESCROW_DAYS,
			'l'   => 'Días de carencia para liberación de puntos',
			'h'   => 'Tiempo en días que los puntos ganados por compra permanecen retenidos antes de pasar a saldo disponible tras completarse el pedido. 0 = acreditación inmediata.',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_program_start'            => array(
			't' => 'text',
			'd' => '2026-08-17 00:00:00',
			'l' => 'Inicio de vigencia del programa',
			'h' => 'Pedidos creados antes de esta fecha no acreditan (formato AAAA-MM-DD HH:MM:SS).',
			's' => 'Puntos por compra',
		),
		'akb_rewards_tiers_enabled'            => array(
			't' => 'yesno',
			'd' => 'yes',
			'l' => 'Niveles de fidelización (Lector, Coleccionista, Residente)',
			'h' => 'Encendido: premia a clientes frecuentes con multiplicadores en compras según sus puntos históricos ganados. Apagado: todas las compras acumulan al multiplicador base 1.0x.',
			's' => 'Puntos por compra',
		),
		'akb_rewards_tier_coleccionista_min'   => array(
			't'   => 'int',
			'd'   => 2000,
			'min' => 100,
			'max' => 50000,
			'l'   => 'Puntos para Nivel Coleccionista',
			'h'   => 'Puntos históricos acumulados que necesita un cliente para alcanzar el nivel Coleccionista (por defecto: 2.000 pts).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_tier_coleccionista_mult'  => array(
			't'    => 'float',
			'd'    => 1.2,
			'min'  => 1.0,
			'max'  => 5.0,
			'step' => '0.05',
			'l'    => 'Multiplicador Nivel Coleccionista',
			'h'    => 'Multiplicador de puntos acumulados en compras para clientes Coleccionista (por defecto: 1.2 = +20% de puntos).',
			's'    => 'Puntos por compra',
		),
		'akb_rewards_tier_residente_min'       => array(
			't'   => 'int',
			'd'   => 8000,
			'min' => 500,
			'max' => 200000,
			'l'   => 'Puntos para Nivel Residente de Akiba',
			'h'   => 'Puntos históricos acumulados para alcanzar el nivel máximo Residente de Akiba (por defecto: 8.000 pts).',
			's'   => 'Puntos por compra',
		),
		'akb_rewards_tier_residente_mult'      => array(
			't'    => 'float',
			'd'    => 1.5,
			'min'  => 1.0,
			'max'  => 5.0,
			'step' => '0.05',
			'l'    => 'Multiplicador Nivel Residente de Akiba',
			'h'    => 'Multiplicador de puntos acumulados en compras para clientes Residente de Akiba (por defecto: 1.5 = +50% de puntos).',
			's'    => 'Puntos por compra',
		),
		'akb_rewards_tier_validity_days'       => array(
			't'      => 'int',
			'd'      => 365,
			'min'    => 0,
			'max'    => 730,
			'suffix' => 'días (365 = 1 año estándar · 0 = vitalicio)',
			'l'      => 'Vigencia del nivel (0 a 730 días)',
			'h'      => 'Días que un cliente conserva su estatus tras alcanzarlo o renovarlo con una compra. 0 = Vitalicio (el estatus no vence jamás).',
			's'      => 'Puntos por compra',
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
			'h' => 'Un correo con enlace personal por pedido pagado, después del despacho o retiro. Cambiar ajustes conserva las fechas ya programadas y no reenvía invitaciones anteriores.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_status' => array(
			't' => 'status',
			'd' => 'completed',
			'l' => 'Estado que inicia la espera para invitar',
			'h' => 'Debe ser siempre «Completado». Al pasar el pedido a Completado, WooCommerce envía de inmediato el correo de despacho/entrega y el sistema inicia la espera programada para invitar a reseñar.',
			's' => 'Reseñas',
		),
		'akb_rewards_invite_delay_days' => array(
			't'   => 'int',
			'd'   => 3,
			// 10x lo vigente y la vida por defecto del enlace: invitar a
			// reseñar más de un mes después del pedido no lo lee nadie.
			'max' => 30,
			'l'   => 'Días de espera tras completar el pedido',
			'h'   => 'Días corridos que el sistema espera tras pasar el pedido a «Completado» (por defecto 3 días) para asegurar que el cliente ya recibió y hojeó sus mangas antes de invitarlo a opinar.',
			's'   => 'Reseñas',
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
			'h'   => 'La invitación por correo, la ficha del tomo y Mi Colección permiten a compradores verificados solicitar puntos adjuntando una foto real de su compra. Se acreditan al aprobar una reseña elegible. Las reseñas sin foto se publican como opinión voluntaria y no generan puntos. 0 apaga el programa de reseñas.',
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
			'h'   => 'Frecuencia de recompensa. Dejar en 1 (recomendado) para premiar cada reseña aprobada de inmediato. Si se fijara en 3, funciona como tarjeta de sellos donde el cliente debe acumular 3 reseñas para recibir los puntos recién en la tercera.',
			's'   => 'Reseñas',
		),
		'akb_rewards_review_max_per_user'      => array(
			't'   => 'int',
			'd'   => 5,
			// 10x el valor VIVO en producción (5), no el default del catálogo
			// (0 = sin tope): medido contra la fila real para que el primer
			// guardado no le baje el número al dueño.
			'max' => 50,
			'l'   => 'Tope mensual de premios por cliente',
			'h'   => '0 = sin tope. Con 5, un cliente puede recibir hasta 5 premios de reseña en una ventana móvil de 30 días. Retirar una reseña premiada revierte sus puntos y libera ese cupo.',
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
			'l' => 'Aviso anticipado en correo de Pedido Completado',
			'h' => 'Añade una breve nota al pie del correo de confirmación de entrega (Pedido completado) avisando al cliente que en 3 días recibirá su correo con enlace personal para reseñar con fotos.',
			's' => 'Reseñas',
		),
		'akb_rewards_nudge_email_ids'          => array(
			't'      => 'emails',
			'd'      => 'customer_completed_order',
			'l'      => 'Correos que llevan el recordatorio',
			'h'      => 'Asociado automáticamente al correo de Pedido Completado.',
			's'      => 'Reseñas',
			'hidden' => true,
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

		$permitidos = array( 'customer_completed_order', 'customer_processing_order' );
		$lista      = array();
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || empty( $email->id ) || ! in_array( (string) $email->id, $permitidos, true ) ) {
				continue;
			}
			$titulo                       = isset( $email->title ) ? trim( (string) $email->title ) : ''; // @phpstan-ignore isset.property
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
		if ( false === strpos( $hook, self::SLUG ) && false === strpos( $hook, 'akb-customer-points' ) && false === strpos( $hook, InvitationReschedule::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'akb-rewards-admin-css', Assets::url( 'assets/css/admin-rewards.css' ), array(), Assets::version( 'assets/css/admin-rewards.css' ) );
		if ( false !== strpos( $hook, self::SLUG ) ) {
			wp_enqueue_script( 'akb-rewards-admin-settings', Assets::url( 'assets/js/admin-settings.js' ), array(), Assets::version( 'assets/js/admin-settings.js' ), true );
		}
	}

	/**
	 * Submenú bajo Panel Akibara (o WooCommerce como fallback defensivo).
	 *
	 * @return void
	 */
	public function add_menu(): void {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['akibara-panel'] ) ? 'akibara-panel' : 'woocommerce';
		add_submenu_page(
			$parent,
			'Puntos y Reseñas',
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
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	public function render(): void { // @phpstan-ignore complexity.functionLike
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs = array(
			'puntos'    => array(
				'label'    => '⭐ Puntos & Fidelización',
				'sections' => array( 'Puntos por compra', 'Cumpleaños', 'Ficha de producto', 'Alertas' ),
			),
			'resenas'   => array(
				'label'    => '💬 Reseñas & Carrusel',
				'sections' => array( 'Reseñas', 'Carrusel del home' ),
				'overview' => true,
			),
			'referidos' => array(
				'label'    => '🤝 Referidos',
				'sections' => array( 'Referidos' ),
			),
		);

		// Los rechazos del último guardado, antes del formulario: si una
		// baranda revierte un campo sin que se vea, es peor que no tenerla. Y
		// con un rechazo en pantalla el aviso verde cambia de frase: el resto
		// del formulario sí se guardó, pero «Ajustes guardados.» a secas debajo
		// de un «No se guardó…» era una contradicción (refutación 2026-09-12).
		$hubo_rechazo = false;
		$avisos       = get_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
		if ( is_array( $avisos ) ) {
			delete_transient( self::TRANSIENT_AVISOS . get_current_user_id() );
		}

		echo '<div class="wrap akb-rewards-wrap"><div class="akb-rewards-panel">';
		echo '<div class="akb-panel-top-bar"><a href="' . esc_url( admin_url( 'admin.php?page=akibara-panel' ) ) . '" class="akb-panel-back-btn">← Volver al Centro de Mando</a></div>';
		echo '<header class="akb-rewards-head">';
		echo '<div class="akb-rewards-brand"><span class="akb-rewards-gem" aria-hidden="true">&#9670;</span><h1>Puntos y Reseñas</h1><span class="akb-rewards-ver">v' . esc_html( AKIBARA_REWARDS_VERSION ) . '</span></div>';
		echo '<nav class="nav-tab-wrapper wp-clearfix" role="tablist">';
		$primero = true;
		foreach ( $tabs as $tab_key => $tab_info ) {
			$activo = $primero;
			echo '<a href="#tab-' . esc_attr( $tab_key ) . '" data-tab="' . esc_attr( $tab_key ) . '" class="nav-tab' . ( $activo ? ' nav-tab-active' : '' ) . '" role="tab" aria-selected="' . ( $activo ? 'true' : 'false' ) . '">' . esc_html( $tab_info['label'] ) . '</a>';
			$primero = false;
		}
		echo '</nav></header>';

		echo '<div class="akb-rewards-body">';

		if ( is_array( $avisos ) ) {
			foreach ( $avisos as $aviso ) {
				if ( ! is_array( $aviso ) || '' === (string) ( $aviso['texto'] ?? '' ) ) {
					continue;
				}
				$hubo_rechazo = $hubo_rechazo || 'error' === ( $aviso['tipo'] ?? '' );
				echo '<div class="notice notice-' . esc_attr( 'error' === ( $aviso['tipo'] ?? '' ) ? 'error' : 'warning' ) . ' is-dismissible"><p>'
					. esc_html( (string) $aviso['texto'] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['akb_rw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			echo '<div class="notice notice-success is-dismissible"><p>' . ( $hubo_rechazo ? 'Se guardó el resto de los ajustes.' : 'Ajustes guardados.' ) . '</p></div>';
		}

		echo '<form data-akb-rewards-settings method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="_akb_rw_preserve_hidden" value="1">';

		$primero = true;
		foreach ( $tabs as $tab_key => $tab_info ) {
			echo '<div class="akb-tab-panel' . ( $primero ? ' is-active' : '' ) . '" id="tab-' . esc_attr( $tab_key ) . '" role="tabpanel">';

			if ( ! empty( $tab_info['overview'] ) ) {
				( new AdminOverview() )->render();
			}

			foreach ( $tab_info['sections'] as $seccion ) {
				echo '<div class="akb-rewards-card" id="' . esc_attr( self::section_anchor( $seccion ) ) . '">';
				echo '<div class="akb-rewards-card__header"><h3 class="akb-rewards-card__title">' . esc_html( $seccion ) . '</h3></div>';
				echo '<table class="form-table" role="presentation">';
				foreach ( self::FIELDS as $option => $campo ) {
					if ( $campo['s'] !== $seccion || ! empty( $campo['hidden'] ) ) {
						continue;
					}
					self::render_field_row( $option, $campo );
				}
				echo '</table></div>';
			}

			echo '</div>'; // akb-tab-panel
			$primero = false;
		}

		echo '<div class="akb-rewards-status-bar">';
		echo '<p data-akb-settings-status role="status" aria-live="polite">Los cambios se aplican al guardar.</p>';
		submit_button( 'Guardar ajustes', 'primary', 'submit', false );
		echo '</div>';

		echo '</form></div></div>'; // akb-rewards-body, akb-rewards-panel

		/**
		 * Bloques extra de la pantalla de ajustes (ajuste manual de puntos,
		 * futuros paneles). Dentro del .wrap, fuera del form de settings.
		 *
		 * @since 0.3.0
		 */
		do_action( 'akb_rewards_settings_after' );

		echo '</div>'; // wrap
	}

	/**
	 * Pinta una fila de campo del formulario de ajustes.
	 *
	 * @param string               $option Option del catálogo.
	 * @param array<string, mixed> $campo  Definición del campo.
	 * @return void
	 */
	private static function render_field_row( string $option, array $campo ): void {
		$const_name     = Options::TIER_CONSTANTS[ $option ] ?? null;
		$const_value    = null !== $const_name ? Options::tier_constant_value( $option ) : null;
		$is_const_fixed = null !== $const_value;
		$valor          = $is_const_fixed ? $const_value : get_option( $option, $campo['d'] );
		$disabled_attr  = $is_const_fixed ? ' disabled="disabled"' : '';

		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( $option ), esc_html( $campo['l'] ) );
		self::render_field_control( $option, $campo, $valor, $disabled_attr );
		self::render_field_help( $option, $campo, $valor, $is_const_fixed, $const_name );
		echo '</td></tr>';
	}

	/**
	 * Pinta el control de entrada (checkbox, select, number, emails, text) para un campo.
	 *
	 * @param string               $option        Option del catálogo.
	 * @param array<string, mixed> $campo         Definición del campo.
	 * @param mixed                $valor         Valor actual.
	 * @param string               $disabled_attr Atributo disabled si aplica.
	 * @return void
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	private static function render_field_control( string $option, array $campo, $valor, string $disabled_attr ): void { // @phpstan-ignore complexity.functionLike
		if ( 'yesno' === $campo['t'] ) {
			printf(
				'<label><input type="checkbox" name="%1$s" id="%1$s" value="yes"%2$s%3$s> Activado</label>',
				esc_attr( $option ),
				checked( 'yes', (string) $valor, false ),
				$disabled_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal estático seguro.
			);
			return;
		}
		if ( 'select' === $campo['t'] ) {
			printf( '<select name="%1$s" id="%1$s"%2$s>', esc_attr( $option ), $disabled_attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $campo['choices'] as $clave => $etiqueta ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $clave ), selected( (string) $valor, $clave, false ), esc_html( $etiqueta ) );
			}
			echo '</select>';
			return;
		}
		if ( 'status' === $campo['t'] ) {
			printf( '<select name="%1$s" id="%1$s">', esc_attr( $option ) );
			$estados = self::order_status_choices();
			if ( '' !== (string) $valor && ! array_key_exists( (string) $valor, $estados ) ) {
				$estados[ (string) $valor ] = $valor . ' (este estado ya no está disponible)';
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
			return;
		}
		if ( 'int' === $campo['t'] || 'float' === $campo['t'] ) {
			self::render_numeric_field_control( $option, $campo, $valor, $disabled_attr );
			return;
		}
		if ( 'emails' === $campo['t'] ) {
			$marcados = array_filter( array_map( 'trim', explode( ',', (string) $valor ) ) );
			$opciones = self::email_choices();
			foreach ( $marcados as $guardado ) {
				if ( ! isset( $opciones[ $guardado ] ) ) {
					$opciones[ $guardado ] = $guardado . ' (este correo ya no está disponible)';
				}
			}
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
			return;
		}
		if ( 'recipients' === $campo['t'] ) {
			printf(
				'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" inputmode="email">',
				esc_attr( $option ),
				esc_attr( (string) $valor ),
				esc_attr( (string) get_option( 'admin_email', '' ) )
			);
			return;
		}

		$placeholder = 'akb_rewards_program_start' === $option ? 'AAAA-MM-DD HH:MM:SS' : '';
		$pattern     = 'akb_rewards_program_start' === $option ? '\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}' : '';
		$inputmode   = 'akb_rewards_program_start' === $option ? 'numeric' : '';
		$maxlength   = isset( $campo['largo'] ) ? (int) $campo['largo'] : 0;

		printf(
			'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text"%3$s%4$s%5$s%6$s>',
			esc_attr( $option ),
			esc_attr( (string) $valor ),
			'' !== $placeholder ? sprintf( ' placeholder="%s"', esc_attr( $placeholder ) ) : '',
			'' !== $pattern ? sprintf( ' pattern="%s"', esc_attr( $pattern ) ) : '',
			'' !== $inputmode ? sprintf( ' inputmode="%s"', esc_attr( $inputmode ) ) : '',
			$maxlength > 0 ? sprintf( ' maxlength="%s"', esc_attr( (string) $maxlength ) ) : ''
		);
	}

	/**
	 * Pinta el control numérico (`int` o `float`) con su sufijo opcional.
	 *
	 * @param string               $option        Option del catálogo.
	 * @param array<string, mixed> $campo         Definición del campo.
	 * @param mixed                $valor         Valor actual.
	 * @param string               $disabled_attr Atributo disabled si aplica.
	 * @return void
	 */
	private static function render_numeric_field_control( string $option, array $campo, $valor, string $disabled_attr ): void {
		$is_float = 'float' === $campo['t'];
		$val_str  = $is_float ? (string) (float) $valor : (string) (int) $valor;
		$min_str  = $is_float ? (string) (float) ( $campo['min'] ?? 1.0 ) : (string) (int) ( $campo['min'] ?? 0 );
		$max_attr = isset( $campo['max'] )
			? ' max="' . esc_attr( $is_float ? (string) (float) $campo['max'] : (string) (int) $campo['max'] ) . '"'
			: '';
		$step_str = $is_float ? (string) ( $campo['step'] ?? '0.05' ) : '1';

		printf(
			'<input type="number" name="%1$s" id="%1$s" value="%2$s" min="%3$s"%4$s step="%5$s" class="small-text"%6$s>',
			esc_attr( $option ),
			esc_attr( $val_str ),
			esc_attr( $min_str ),
			$max_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $step_str ),
			$disabled_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		if ( isset( $campo['suffix'] ) && '' !== $campo['suffix'] ) {
			printf( ' <span class="akb-field-suffix" style="margin-left:6px;color:var(--akb-admin-muted,#646970)">%s</span>', esc_html( $campo['suffix'] ) );
		}
	}

	/**
	 * Pinta la descripción y avisos contextuales de un campo.
	 *
	 * @param string               $option         Option del catálogo.
	 * @param array<string, mixed> $campo          Definición del campo.
	 * @param mixed                $valor          Valor actual.
	 * @param bool                 $is_const_fixed Si está fijado por constante.
	 * @param string|null          $const_name     Nombre de la constante.
	 * @return void
	 */
	private static function render_field_help( string $option, array $campo, $valor, bool $is_const_fixed, ?string $const_name ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		if ( '' === $campo['h'] ) {
			return;
		}
		printf(
			'<p class="description">%s</p>',
			esc_html( $campo['h'] . ( isset( $campo['largo'] ) ? ' Hasta ' . (int) $campo['largo'] . ' caracteres.' : '' ) )
		);

		if ( $is_const_fixed && null !== $const_name ) {
			printf(
				'<p class="description" style="color:var(--akb-admin-warning,#996800)"><strong>Controlado por constante <code>%s</code> en <code>wp-config.php</code></strong> (prevalece sobre la base de datos).</p>',
				esc_html( $const_name )
			);
		}

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

		if ( 'status' === (string) $campo['t'] ) {
			$evidencia = self::status_evidence_text( self::status_counts_90d( (string) $valor ), (string) $valor, self::order_status_choices(), $option );
			if ( '' !== $evidencia ) {
				printf( '<p class="description" style="color:#996800"><strong>%s</strong></p>', esc_html( $evidencia ) );
			}
		}

		if ( in_array( $option, array( 'akb_rewards_review_nudge_enabled', 'akb_rewards_nudge_email_ids' ), true )
			
			&& 'yes' === (string) get_option( 'akb_rewards_review_nudge_enabled', 'yes' )
			&& array() === array_filter( array_map( 'trim', explode( ',', (string) get_option( 'akb_rewards_nudge_email_ids', 'customer_completed_order' ) ) ) ) ) {
			printf(
				'<p class="description" style="color:#996800"><strong>%s</strong></p>',
				esc_html( 'Ojo: está activado, pero no hay ningún correo marcado abajo, así que el párrafo no sale en ninguno. Marca al menos uno, o apaga el interruptor.' )
			);
		}

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
	public function save_fields( array $input ): array { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$avisos   = array();
		$acotados = array();
		$valores  = $this->sanitize_fields( $input, $avisos, $acotados );

		$valores = $this->guard_giveback( $valores, $avisos );
		$valores = $this->guard_tiers( $valores, $avisos, $input );
		$valores = $this->guard_expiry_warning( $valores, $avisos );

		foreach ( $acotados as $option => $acotado ) {
			$tipo = self::FIELDS[ $option ]['t'] ?? 'int';
			if ( 'float' === $tipo ) {
				if ( abs( (float) $valores[ $option ] - (float) $acotado ) <= 0.0001 ) {
					$min      = (float) ( self::FIELDS[ $option ]['min'] ?? 0.0 );
					$max      = (float) ( self::FIELDS[ $option ]['max'] ?? 10.0 );
					$avisos[] = array(
						'tipo'  => 'warning',
						'texto' => self::etiqueta( $option ) . ' admite entre ' . \Akibara\Rewards\Points\Tiers::format_multiplier( $min ) . ' y ' . \Akibara\Rewards\Points\Tiers::format_multiplier( $max ) . ': se guardó en ' . \Akibara\Rewards\Points\Tiers::format_multiplier( (float) $acotado ) . '.',
					);
				}
			} elseif ( (int) $valores[ $option ] === (int) $acotado ) {
				$min      = (int) ( self::FIELDS[ $option ]['min'] ?? 0 );
				$max      = (int) self::FIELDS[ $option ]['max'];
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => self::etiqueta( $option ) . ' admite entre ' . number_format_i18n( $min ) . ' y ' . number_format_i18n( $max ) . ': se guardó en ' . number_format_i18n( (int) $acotado ) . '.',
				);
			}
		}

		// Captura las opciones incluidas en el HTML de las fichas para emitir
		// una sola invalidación si cambia cualquiera de ellas.
		$ficha_antes = array();
		foreach ( array_merge( self::PROMESA_EN_CADA_FICHA, self::FORMA_DE_CADA_FICHA ) as $option ) {
			$ficha_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
		}
		// Las opciones del carrusel gobiernan su
		// contenido cacheado; la lista se obtiene del catálogo del panel.
		$resenas_antes = array();
		foreach ( array_keys( self::FIELDS ) as $option ) {
			if ( 0 === strpos( $option, 'akb_rewards_carousel_' ) ) {
				$resenas_antes[ $option ] = (string) get_option( $option, (string) self::FIELDS[ $option ]['d'] );
			}
		}

		$preserve_hidden = ! empty( $input['_akb_rw_preserve_hidden'] );

		foreach ( array_keys( self::FIELDS ) as $option ) {
			if ( null !== Options::tier_constant_value( $option ) ) {
				continue;
			}
			if ( $preserve_hidden && ! empty( self::FIELDS[ $option ]['hidden'] ) && ! array_key_exists( $option, $input ) ) {
				continue;
			}
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
		$valores         = array();
		$preserve_hidden = ! empty( $input['_akb_rw_preserve_hidden'] );

		foreach ( self::FIELDS as $option => $campo ) {
			$const_val = Options::tier_constant_value( $option );
			if ( null !== $const_val ) {
				$valores[ $option ] = $const_val;
				continue;
			}
			if ( $preserve_hidden && ! empty( $campo['hidden'] ) && ! array_key_exists( $option, $input ) ) {
				$valores[ $option ] = get_option( $option, $campo['d'] );
				continue;
			}
			$valores[ $option ] = $this->sanitize_single_field_value( $option, $campo, $input, $avisos, $acotados ); // @phpstan-ignore parameterByRef.type
		}

		return $valores;
	}

	/**
	 * Sanea un campo individual del catálogo según su tipo.
	 *
	 * @param string                                     $option   Nombre de la option.
	 * @param array<string, mixed>                       $campo    Definición del campo.
	 * @param array<string, mixed>                       $input    Datos recibidos.
	 * @param array<int,array{tipo:string,texto:string}> $avisos   Avisos (por referencia).
	 * @param array<string, int|float>                   $acotados Campos acotados (por referencia).
	 * @return mixed
	 */
	private function sanitize_single_field_value( string $option, array $campo, array $input, array &$avisos, array &$acotados ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		if ( 'yesno' === $campo['t'] ) {
			return isset( $input[ $option ] ) && 'yes' === $input[ $option ] ? 'yes' : 'no';
		}
		if ( 'select' === $campo['t'] ) {
			$valor = isset( $input[ $option ] ) && is_string( $input[ $option ] ) ? $input[ $option ] : (string) get_option( $option, $campo['d'] );
			if ( ! array_key_exists( $valor, $campo['choices'] ) ) {
				$guardado = (string) get_option( $option, $campo['d'] );
				$valor    = array_key_exists( $guardado, $campo['choices'] ) ? $guardado : $campo['d'];
				$avisos[] = array( 'tipo' => 'warning', 'texto' => '«' . $campo['l'] . '» no contiene una opción válida: se conservó la selección anterior.' );
			}
			return $valor;
		}
		if ( 'status' === $campo['t'] ) {
			return $this->sanitize_status_field_value( $option, $campo, $input, $avisos );
		}
		if ( 'int' === $campo['t'] ) {
			$crudo = isset( $input[ $option ] ) ? (int) $input[ $option ] : (int) $campo['d'];
			$valor = self::clamp_int( $option, $crudo );
			if ( isset( $input[ $option ] ) && $valor !== $crudo ) {
				$acotados[ $option ] = $valor;
			}
			return $valor;
		}
		if ( 'float' === $campo['t'] ) {
			return $this->sanitize_float_field_value( $option, $campo, $input, $acotados );
		}
		if ( 'emails' === $campo['t'] ) {
			return $this->sanitize_emails_field_value( $option, $campo, $input );
		}
		if ( 'recipients' === $campo['t'] ) {
			$descartados = array();
			$valor       = implode( ', ', Options::email_list( sanitize_text_field( (string) ( $input[ $option ] ?? '' ) ), $descartados ) );
			if ( array() !== $descartados ) {
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => 'En «' . $campo['l'] . '» se descartó lo que no es un correo: ' . implode( ', ', $descartados ) . '.',
				);
			}
			return $valor;
		}

		return $this->sanitize_text_field_value( $option, $campo, $input, $avisos );
	}

	/**
	 * Sanea un campo de estado de pedido (`status`).
	 *
	 * @param string                                     $option Nombre de la option.
	 * @param array<string, mixed>                       $campo  Definición del campo.
	 * @param array<string, mixed>                       $input  Datos recibidos.
	 * @param array<int,array{tipo:string,texto:string}> $avisos Avisos (por referencia).
	 * @return string
	 */
	private function sanitize_status_field_value( string $option, array $campo, array $input, array &$avisos ): string {
		$valor    = sanitize_key( (string) ( $input[ $option ] ?? '' ) );
		$guardado = (string) get_option( $option, (string) $campo['d'] );
		if ( ! array_key_exists( $valor, self::order_status_choices() ) ) {
			if ( '' !== $valor && $valor === $guardado ) {
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => '«' . $campo['l'] . '» se quedó como estaba (' . $valor . '): ese estado no existe hoy en la tienda, seguramente porque el plugin que lo registra está desactivado.',
				);
			} else {
				$valor = '' !== $guardado && array_key_exists( $guardado, self::order_status_choices() )
					? $guardado
					: (string) $campo['d'];
			}
		}
		return $valor;
	}

	/**
	 * Sanea un campo numérico decimal (`float`).
	 *
	 * @param string                   $option   Nombre de la option.
	 * @param array<string, mixed>     $campo    Definición del campo.
	 * @param array<string, mixed>     $input    Datos recibidos.
	 * @param array<string, int|float> $acotados Campos acotados (por referencia).
	 * @return float
	 */
	private function sanitize_float_field_value( string $option, array $campo, array $input, array &$acotados ): float {
		$min = isset( $campo['min'] ) ? (float) $campo['min'] : 0.0;
		$max = isset( $campo['max'] ) ? (float) $campo['max'] : 10.0;
		if ( ! isset( $input[ $option ] ) || ! is_scalar( $input[ $option ] ) ) {
			return (float) $campo['d'];
		}
		$raw_norm = str_replace( ',', '.', trim( (string) $input[ $option ] ) );
		$crudo    = is_numeric( $raw_norm ) ? round( (float) $raw_norm, 2 ) : (float) $campo['d'];
		$valor    = round( max( $min, min( $max, $crudo ) ), 2 );
		if ( ! is_numeric( $raw_norm ) || abs( $valor - $crudo ) > 0.0001 ) {
			$acotados[ $option ] = $valor;
		}
		return $valor;
	}

	/**
	 * Sanea un campo de selección múltiple de correos (`emails`).
	 *
	 * @param string               $option Nombre de la option.
	 * @param array<string, mixed> $campo  Definición del campo.
	 * @param array<string, mixed> $input  Datos recibidos.
	 * @return string
	 */
	private function sanitize_emails_field_value( string $option, array $campo, array $input ): string {
		if ( ! isset( $input[ $option ] ) ) {
			return '';
		}
		$guardados = array_filter( array_map( 'trim', explode( ',', (string) get_option( $option, $campo['d'] ) ) ) );
		$opciones  = self::email_choices();
		if ( array() === $opciones ) {
			return implode( ',', $guardados );
		}
		$validos = array_values( array_unique( array_merge( array_keys( $opciones ), $guardados ) ) );
		return implode( ',', array_values( array_intersect( $validos, array_map( 'strval', (array) $input[ $option ] ) ) ) );
	}

	/**
	 * Sanea un campo de texto libre o fecha de inicio de vigencia (`text`).
	 *
	 * @param string                                     $option Nombre de la option.
	 * @param array<string, mixed>                       $campo  Definición del campo.
	 * @param array<string, mixed>                       $input  Datos recibidos.
	 * @param array<int,array{tipo:string,texto:string}> $avisos Avisos (por referencia).
	 * @return string
	 */
	private function sanitize_text_field_value( string $option, array $campo, array $input, array &$avisos ): string {
		$valor = isset( $input[ $option ] ) ? sanitize_text_field( (string) $input[ $option ] ) : (string) $campo['d'];
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
		if ( 'akb_rewards_program_start' === $option && null === \Akibara\Rewards\Points\Accrual::parse_program_start( $valor ) ) {
			$valor    = (string) get_option( $option, $campo['d'] );
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => 'La fecha de «Inicio de vigencia del programa» no tiene el formato AAAA-MM-DD HH:MM:SS: se conservó la anterior. Con una fecha inválida nadie acreditaría puntos.',
			);
		}
		return $valor;
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
	 * Acota un flotante de dos decimales al rango declarado en su fila.
	 *
	 * @param string $option Option del catálogo.
	 * @param float  $valor  Valor pretendido.
	 * @return float
	 */
	private static function clamp_float( string $option, float $valor ): float {
		$campo = self::FIELDS[ $option ];
		$valor = max( (float) ( $campo['min'] ?? 0 ), $valor );
		if ( isset( $campo['max'] ) ) {
			$valor = min( (float) $campo['max'], $valor );
		}

		return round( $valor, 2 );
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
	 * BARANDA DE COHERENCIA DE NIVELES: Residente de Akiba debe exigir más puntos y
	 * otorgar un multiplicador igual o superior al de Coleccionista. Asimismo advierte
	 * si el multiplicador máximo configurado hace que el retorno efectivo supere el techo.
	 *
	 * @param array<string,mixed>                        $valores Valores saneados.
	 * @param array<int,array{tipo:string,texto:string}> $avisos  Avisos, por referencia.
	 * @param array<string,mixed>                        $input   Entrada cruda para detectar edición explícita de multiplicador.
	 * @return array<string,mixed>
	 */
	private function guard_tiers( array $valores, array &$avisos, array $input ): array {
		$campos = array(
			'akb_rewards_tier_coleccionista_min',
			'akb_rewards_tier_residente_min',
			'akb_rewards_tier_coleccionista_mult',
			'akb_rewards_tier_residente_mult',
		);

		$valores = $this->aplicar_baranda(
			$valores,
			$avisos,
			$campos,
			static function ( array $v ): bool {
				return (int) $v['akb_rewards_tier_residente_min'] <= (int) $v['akb_rewards_tier_coleccionista_min']
					|| round( (float) $v['akb_rewards_tier_residente_mult'], 2 ) < round( (float) $v['akb_rewards_tier_coleccionista_mult'], 2 );
			},
			static function (): string {
				return 'Residente de Akiba debe requerir más puntos acumulados que Coleccionista y ofrecer un multiplicador igual o mayor.';
			},
			'akb_rewards_tiers_enabled'
		);

		$ceiling_triggers = array(
			'akb_rewards_tier_residente_mult',
			'akb_rewards_tier_coleccionista_mult',
			'akb_rewards_earn_points',
			'akb_rewards_earn_amount',
			'akb_rewards_redeem_value',
			'akb_rewards_max_giveback_pct',
			'akb_rewards_tiers_enabled',
			'akb_rewards_earn_enabled',
		);
		$touched_ceiling  = array() !== array_intersect( $ceiling_triggers, array_keys( $input ) );

		if (
			'yes' === (string) ( $valores['akb_rewards_tiers_enabled'] ?? 'no' )
			&& 'yes' === (string) ( $valores['akb_rewards_earn_enabled'] ?? 'no' )
			&& $touched_ceiling
		) {
			$base_pct      = Calculator::giveback_pct(
				(int) $valores['akb_rewards_earn_points'],
				(int) $valores['akb_rewards_earn_amount'],
				(int) $valores['akb_rewards_redeem_value']
			);
			$effective_pct = $base_pct * (float) $valores['akb_rewards_tier_residente_mult'];
			$ceiling_pct   = (int) $valores['akb_rewards_max_giveback_pct'];
			if ( $ceiling_pct > 0 && $effective_pct > (float) $ceiling_pct ) {
				$avisos[] = array(
					'tipo'  => 'warning',
					'texto' => sprintf(
						'Advertencia comercial: con el multiplicador %sx en Residente de Akiba, un cliente en nivel máximo obtendría %s efectivo en compras (sobre el techo base de %s).',
						Tiers::format_multiplier( (float) $valores['akb_rewards_tier_residente_mult'], ',' ),
						self::pct_texto( $effective_pct ),
						self::pct_texto( (float) $ceiling_pct )
					),
				);
			}
		}

		return $valores;
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
			$tipo = self::FIELDS[ $option ]['t'] ?? 'int';
			if ( 'float' === $tipo ) {
				$raw_prev           = get_option( $option, self::FIELDS[ $option ]['d'] );
				$previos[ $option ] = self::clamp_float( $option, is_numeric( $raw_prev ) ? (float) $raw_prev : (float) self::FIELDS[ $option ]['d'] );
				if ( abs( (float) $previos[ $option ] - (float) $valores[ $option ] ) > 0.0001 ) {
					$cambiados[] = $option;
				}
			} else {
				$previos[ $option ] = self::clamp_int( $option, (int) get_option( $option, self::FIELDS[ $option ]['d'] ) );
				if ( $previos[ $option ] !== (int) $valores[ $option ] ) {
					$cambiados[] = $option;
				}
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
			$tipo               = self::FIELDS[ $option ]['t'] ?? 'int';
			$texto_valor        = 'float' === $tipo
				? Tiers::format_multiplier( (float) $previos[ $option ], ',' )
				: number_format_i18n( (int) $previos[ $option ] );
			$conservado[]       = self::etiqueta( $option ) . ' en ' . $texto_valor;
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
				// Estados no nativos fuera: Blue Express y otros couriers operan solo bajo estados nativos.
				if ( in_array( $clave, array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'shipping-progress' ), true ) ) {
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

		static $memo = array();
		if ( isset( $memo[ $current ] ) ) {
			return $memo[ $current ];
		}

		$estados = OrderStates::PAID;
		if ( '' !== $current && ! in_array( $current, $estados, true ) ) {
			$estados[] = $current;
		}

		$desde = Dates::to_datetime()->modify( '-90 days' )->format( 'Y-m-d' );
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

		$memo[ $current ] = $out;
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
