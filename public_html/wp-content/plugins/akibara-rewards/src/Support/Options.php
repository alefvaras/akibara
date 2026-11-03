<?php
/**
 * Options — lectura tipada de la configuración del programa.
 *
 * Los valores por defecto replican las reglas base del programa de recompensas:
 * 2 puntos por cada $100 sobre subtotal tras descuentos con IVA, sin incluir envío;
 * canje 1 punto = $1 CLP; bono de bienvenida de 500 puntos; estado de acreditación
 * «Procesando» (ver `award_status()`).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Options {

	/**
	 * Tope por defecto del canje, en porcentaje del pedido. Es SOLO el valor
	 * inicial del campo del panel: el dueño lo cambia en WooCommerce → Puntos
	 * y Reseñas sin tocar código (regla de la casa).
	 */
	public const DEFAULT_REDEEM_MAX_PCT = 50;

	/**
	 * Tope por defecto del canje en pesos por pedido. Igual que el porcentual:
	 * es sólo el valor inicial del campo; el dueño manda desde el panel.
	 * El mínimo de carrito se configura por separado mediante redeem_min_cart().
	 */
	public const DEFAULT_REDEEM_MAX_AMOUNT = 5000;

	/**
	 * Estado de pedido que acredita, de fábrica. Es sólo el valor inicial del
	 * selector del panel; el dueño manda desde WooCommerce → Puntos y Reseñas.
	 *
	 * «Procesando» es, en Akibara, el estado al que llega el pedido cuando la
	 * pasarela confirma el pago.
	 */
	public const DEFAULT_AWARD_STATUS = 'processing';

	/**
	 * Techo de fábrica de la RAZÓN del programa: cuántos pesos puede devolver
	 * la tienda por cada 100 gastados, contando las tres patas a la vez
	 * (puntos por tramo ÷ tamaño del tramo × pesos por punto). Hoy la razón
	 * vigente es 2 % (2 puntos por cada $100, a $1 el punto), así que 15 es
	 * 7,5 veces lo que la tienda regala: es el umbral del ABSURDO, no el
	 * óptimo.
	 *
	 * El número es el MISMO 15 del techo de descuento de la tienda
	 * (Akibara\Business\Utilities\DiscountCeiling::DEFAULT_PCT) porque un
	 * punto regalado es margen que se va igual que un descuento. Y como allá,
	 * es un AJUSTE del panel («Techo de devolución del programa»), no un muro
	 * en el código: la regla de la casa es que todo se opere sin programar.
	 * Lo que sí vive en código es HARD_MAX_GIVEBACK_PCT, el límite del campo.
	 *
	 * DECISIÓN EXPLÍCITA: el 15 se COPIA, no se enlaza con
	 * `akb_discount_max_pct`. Si esto lo leyera en vivo, una campaña que suba
	 * el techo de descuento al 30 % desbloquearía en silencio un programa de
	 * puntos al 30 %, y rewards cambiaría de conducta si business se desactiva.
	 */
	public const DEFAULT_MAX_GIVEBACK_PCT = 15;

	/**
	 * Límite del campo del techo. Devolver la mitad de cada compra en puntos
	 * ya no es un programa de fidelidad, es una rebaja disfrazada: es la
	 * baranda contra el tecleo (150, 1500), como los `max` del catálogo.
	 *
	 * Las dos constantes viven acá porque las consume la fila del catálogo
	 * (`SettingsPage::FIELDS['akb_rewards_max_giveback_pct']`, 'd' y 'max'), y
	 * ese catálogo es el ÚNICO que lee el techo: es una baranda del GUARDADO
	 * —el panel no deja persistir una razón que lo supere—, no una regla que
	 * el motor de puntos consulte en cada compra. Quien lea la option en
	 * runtime tiene que acotarla como lo hace `SettingsPage::clamp_int()`, que
	 * aplica el mismo min/max de la fila; no hay un segundo acotador aparte.
	 */
	public const HARD_MAX_GIVEBACK_PCT = 50;

	/**
	 * Puntos otorgados por cada tramo de $earn_amount().
	 *
	 * @return int
	 */
	public static function earn_points(): int {
		if ( ! self::lane_on( 'akb_rewards_earn_enabled' ) ) {
			return 0; // Interruptor del carril: apagado = nadie acumula.
		}
		return max( 0, (int) get_option( 'akb_rewards_earn_points', 2 ) );
	}

	/**
	 * Interruptor de UN CLIC por carril (todo activable/desactivable
	 * «ahora o después» por administración).
	 * Vive en el ACCESSOR a propósito: apagarlo aquí apaga a la vez el motor,
	 * los correos, Mi cuenta, la ficha y la página pública — nadie más tiene
	 * que preguntar. Los montos guardados se conservan para re-encender.
	 *
	 * @param string $option Option yes/no del carril.
	 * @return bool
	 */
	private static function lane_on( string $option ): bool {
		return 'yes' === (string) get_option( $option, 'yes' );
	}

	/**
	 * ¿El carrusel de la portada está encendido? El clic del panel manda.
	 *
	 * @return bool
	 */
	public static function carousel_on(): bool {
		return self::lane_on( 'akb_rewards_carousel_enabled' );
	}

	/**
	 * Tamaño del tramo en pesos sobre el que se otorgan earn_points().
	 *
	 * @return int
	 */
	public static function earn_amount(): int {
		// 0 APAGA la acumulación (la guarda de Calculator devuelve 0 puntos).
		// Evita que un campo vacío se convierta indebidamente en tramo de $1.
		return max( 0, (int) get_option( 'akb_rewards_earn_amount', 100 ) );
	}

	/**
	 * Estado de pedido que dispara la acreditación (sin prefijo wc-).
	 *
	 * Invariante de negocio: «Procesando» es el estado al que llega el pedido
	 * cuando la pasarela confirma el PAGO. El cliente que acaba de pagar ve sus
	 * puntos en el acto, en vez de esperar a despacho físico; lo que se acredita
	 * de más si la venta se cae lo devuelve la reversa (cancelado, fallido o
	 * reembolsado deduce todo).
	 *
	 * El panel lo gobierna vía `akb_rewards_award_status`.
	 *
	 * @return string
	 */
	public static function award_status(): string {
		$status = (string) get_option( 'akb_rewards_award_status', self::DEFAULT_AWARD_STATUS );
		return '' !== $status ? $status : self::DEFAULT_AWARD_STATUS;
	}

	/**
	 * Compra mínima, en pesos pagados con dinero, para el bono de bienvenida y
	 * el premio a quien invitó.
	 *
	 * Política de negocio: Sin compra mínima para el bono de bienvenida.
	 * El freno contra el farmeo lo pone el carrito mínimo del canje: el bono
	 * sólo se gasta dentro de una compra estándar completa.
	 * Con 0 basta con que haya dinero: una compra pagada entera con puntos nunca cuenta.
	 *
	 * @return int
	 */
	public static function first_purchase_min(): int {
		return max( 0, (int) get_option( 'akb_rewards_first_purchase_min', 0 ) );
	}

	/**
	 * Con qué señales se reconoce a la misma persona en una compra anterior.
	 *
	 * Señales antifraude: RUT, teléfono y correo electrónico. Cada señal
	 * tiene su interruptor por si alguna da falsos positivos (un teléfono
	 * familiar compartido, por ejemplo); la cuenta del cliente se compara
	 * siempre.
	 *
	 * @return array<int,string> Subconjunto de rut, phone, email.
	 */
	public static function first_purchase_checks(): array {
		$senales = array(
			'rut'   => 'akb_rewards_first_purchase_by_rut',
			'phone' => 'akb_rewards_first_purchase_by_phone',
			'email' => 'akb_rewards_first_purchase_by_email',
		);

		$activas = array();
		foreach ( $senales as $senal => $opcion ) {
			if ( 'yes' === (string) get_option( $opcion, 'yes' ) ) {
				$activas[] = $senal;
			}
		}

		return $activas;
	}

	/**
	 * Carrito mínimo, en pesos de productos, para poder canjear puntos.
	 *
	 * Carrito mínimo estándar: $50.000. Es lo que permite regalar puntos
	 * (bienvenida sin mínimo, cumpleaños, reseñas, referidos) sin riesgo de
	 * farmeo: sólo se gastan dentro de una compra sustancial. 0 = sin mínimo.
	 *
	 * @return int
	 */
	public static function redeem_min_cart(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_min_cart', 50000 ) );
	}

	/**
	 * Estado del pedido del invitado que paga el premio a quien invitó.
	 *
	 * Estado de activación de referidos: «Completado». El premio se otorga
	 * cuando la compra ya se entregó; si posteriormente se anula o reembolsa
	 * por completo, la reversa descuenta el crédito.
	 *
	 * @return string Estado sin prefijo wc-.
	 */
	public static function referral_status(): string {
		$status = (string) get_option( 'akb_rewards_referral_status', 'completed' );
		return '' !== $status ? $status : 'completed';
	}

	/**
	 * Inicio de vigencia del programa (hora local del sitio).
	 *
	 * Pedidos CREADOS antes de esta fecha no acreditan aunque cambien de
	 * estado después: sin esta compuerta, mover un pedido antiguo a «Listo
	 * para enviar» regalaría el 2% + bono retroactivo (lección del 22-08).
	 *
	 * @return string Formato Y-m-d H:i:s.
	 */
	public static function program_start(): string {
		$start = (string) get_option( 'akb_rewards_program_start', '2026-08-17 00:00:00' );
		return '' !== $start ? $start : '2026-08-17 00:00:00';
	}

	/**
	 * Bono de bienvenida en puntos. 0 = desactivado.
	 *
	 * @return int
	 */
	public static function welcome_bonus(): int {
		if ( ! self::lane_on( 'akb_rewards_welcome_enabled' ) ) {
			return 0;
		}

		/**
		 * Permite ajustar el bono por código (campañas).
		 *
		 * @param int $bonus Puntos del bono.
		 */
		return max( 0, (int) apply_filters( 'akb_rewards_welcome_bonus', (int) get_option( 'akb_rewards_welcome_bonus', 500 ) ) );
	}

	/**
	 * «1 punto = $N» para las superficies (correo, Mi cuenta): SIEMPRE
	 * derivado de redeem_value — el «$1» escrito a mano contradecía la
	 * config en la misma frase.
	 *
	 * @return string
	 */
	public static function redeem_label(): string {
		return self::points_label( 1 );
	}

	/**
	 * Cuánto valen N puntos, en pesos y ya formateado («$480»).
	 *
	 * La equivalencia es el gancho: «480 puntos» obliga al cliente a hacer una
	 * cuenta que no tiene por qué saber hacer, «= $480» se entiende de una
	 * lectura directa en la ficha de producto. Vive aquí, junto al cambio,
	 * para que ninguna superficie lo multiplique por su cuenta: si cambia
	 * la conversión del punto, se actualizan todas a la vez.
	 *
	 * @param int $points Puntos a convertir.
	 * @return string
	 */
	public static function points_label( int $points ): string {
		return '$' . number_format_i18n( max( 0, $points ) * self::redeem_value() );
	}

	/**
	 * ¿El MOTOR propio de reseñas está despierto? Autoridad ÚNICA de la
	 * pregunta: la consultan el arranque (qué módulos cuelgan) y las siete
	 * superficies de copy. Con dos autoridades, el filtro documentado sólo lo
	 * obedecía una y el arranque decidía por su cuenta con un class_exists
	 * crudo.
	 *
	 * OJO: el arranque la llama en `plugins_loaded`, así que un filtro
	 * registrado más tarde no cambia qué módulos cuelgan — sólo el copy.
	 *
	 * @return bool
	 */
	public static function reviews_engine_on(): bool {
		/**
		 * Permite forzar el estado del motor de reseñas (pruebas, cutover).
		 *
		 * @param bool $on Despierto si CusRev no está activo.
		 */
		return (bool) apply_filters( 'akb_rewards_reviews_engine_on', ! class_exists( '\\CR_Reviews' ) );
	}

	/**
	 * ¿El carril de reseñas-con-puntos está VIVO? Exige monto > 0 y que el
	 * motor propio esté despierto (duerme mientras CusRev viva): ninguna
	 * superficie puede prometer un premio que ningún código puede pagar.
	 *
	 * @return bool
	 */
	public static function reviews_lane_on(): bool {
		return self::review_points() > 0 && self::reviews_engine_on();
	}

	/**
	 * Mínimo de puntos por canje (patrón estándar de fidelización).
	 * 0 = sin mínimo. Vive aquí para que el motor, Mi cuenta y la
	 * página pública obedezcan al unísono.
	 *
	 * @return int
	 */
	public static function redeem_min_points(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_min_points', 100 ) );
	}

	/**
	 * Porcentaje MÁXIMO del pedido que se puede pagar con puntos.
	 *
	 * Sin tope, el canje podría cubrir el 100 % de las líneas: un cliente con
	 * saldo se llevaría productos sin flujo de caja. El tope porcentual
	 * protege los márgenes operativos de la tienda.
	 *
	 * 0 = sin tope (comportamiento anterior). Se acota a 100 porque más no
	 * significa nada: el canje jamás puede superar las líneas del carrito.
	 *
	 * @return int Porcentaje entre 0 y 100.
	 */
	public static function redeem_max_pct(): int {
		$pct = (int) get_option( 'akb_rewards_redeem_max_pct', self::DEFAULT_REDEEM_MAX_PCT );

		return max( 0, min( 100, $pct ) );
	}

	/**
	 * Tope del canje en PESOS por pedido. 0 = sin tope en pesos.
	 *
	 * Convive con el porcentual y rige el más restrictivo de los dos. Un tope
	 * nominal en pesos («$5.000 máximo») evita que el comprador tenga que
	 * calcular porcentajes mentales para saber cuánto puede descontar.
	 *
	 * @return int
	 */
	public static function redeem_max_amount(): int {
		return max( 0, (int) get_option( 'akb_rewards_redeem_max_amount', self::DEFAULT_REDEEM_MAX_AMOUNT ) );
	}

	/**
	 * Pesos descontados por punto canjeado (valor facial, IVA incluido).
	 *
	 * ENTERO: la tienda cobra en CLP, que no tiene centavos, y la pantalla de
	 * ajustes declara el campo como int. Devolverlo float sostenía una rama de
	 * dos decimales en redeem_label() que ningún valor guardable alcanzaba.
	 *
	 * @return int
	 */
	public static function redeem_value(): int {
		$value = (int) get_option( 'akb_rewards_redeem_value', 1 );
		return $value > 0 ? $value : 1;
	}

	/**
	 * Referidos: el interruptor y el monto del carril «invita a un amigo».
	 * Vive acá como los demás carriles — Referrals leía sus cuatro ajustes con
	 * get_option crudo y se saltaba la fuente única del programa.
	 *
	 * @return int
	 */
	public static function referral_points(): int {
		if ( ! self::lane_on( 'akb_rewards_referral_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_referral_points', 500 ) );
	}

	/**
	 * Días que la visita con ?ref= se recuerda antes de crear la cuenta.
	 *
	 * @return int
	 */
	public static function referral_cookie_days(): int {
		return max( 1, min( 90, (int) get_option( 'akb_rewards_referral_cookie_days', 30 ) ) );
	}

	/**
	 * Tope de premios por padrino EN LA HISTORIA. 0 = sin tope.
	 *
	 * @return int
	 */
	public static function referral_max_per_user(): int {
		return max( 0, (int) get_option( 'akb_rewards_referral_max_per_user', 0 ) );
	}

	/**
	 * Puntos por reseña con foto APROBADA. 0 = programa apagado.
	 *
	 * La reseña con foto aprobada otorga PUNTOS (no cupones de un solo uso),
	 * acreditados tras su moderación exitosa (500 puntos por defecto).
	 *
	 * @return int
	 */
	public static function review_points(): int {
		if ( ! self::lane_on( 'akb_rewards_reviews_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_review_points', 500 ) );
	}

	/**
	 * Puntos por cumpleaños (una vez al año). 0 = apagado.
	 *
	 * Puntos por cumpleaños: 1.000 de fábrica (superior al bono de bienvenida
	 * para premiar lealtad anual).
	 *
	 * @return int
	 */
	public static function birthday_points(): int {
		if ( ! self::lane_on( 'akb_rewards_birthday_enabled' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( 'akb_rewards_birthday_points', 1000 ) );
	}

	/**
	 * Largo mínimo del texto para que una reseña sea elegible del carrusel.
	 *
	 * @return int
	 */
	public static function carousel_min_chars(): int {
		return max( 1, (int) get_option( 'akb_rewards_carousel_min_chars', 40 ) );
	}

	/**
	 * Máximo de tarjetas pintadas en el carrusel.
	 *
	 * @return int
	 */
	public static function carousel_cards(): int {
		return max( 1, min( 20, (int) get_option( 'akb_rewards_carousel_cards', 8 ) ) );
	}

	/**
	 * Tipo de reseñas elegido en el panel; la portada admite ambas por defecto.
	 *
	 * @return string mixed|photos|text.
	 */
	public static function carousel_media(): string {
		$mode = (string) get_option( 'akb_rewards_carousel_media', 'mixed' );
		return in_array( $mode, array( 'mixed', 'photos', 'text' ), true ) ? $mode : 'mixed';
	}

	/** Permite mostrar el aviso de puntos en el carrusel sin cambiar el de las fichas. */
	public static function carousel_disclosure_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_carousel_disclosure_enabled', 'no' );
	}

	/**
	 * ¿Se pinta el aviso de incentivo en las reseñas premiadas?
	 *
	 * Estado del aviso de incentivo: desactivado por defecto. Permite activar
	 * el aviso de divulgación con un clic en el panel administrativo según
	 * las pautas de datos estructurados de motores de búsqueda.
	 *
	 * @return bool
	 */
	public static function disclosure_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_disclosure_enabled', 'no' );
	}

	/**
	 * Título de las reseñas: el del carrusel de la portada y, en el tema, el de
	 * la sección de reseñas de cada ficha (regla F: configurable). En el panel,
	 * «Título de las reseñas (portada y fichas)».
	 *
	 * @return string
	 */
	public static function carousel_title(): string {
		$title = (string) get_option( 'akb_rewards_carousel_title', 'Voces del Distrito' );
		return '' !== $title ? $title : 'Voces del Distrito';
	}

	/**
	 * Cada cuántas reseñas calificadas se paga el premio. 1 = cada reseña.
	 *
	 * Frecuencia de acreditación: configurable para otorgar puntos en cada
	 * reseña o cada N reseñas aprobadas.
	 *
	 * @return int
	 */
	public static function review_every_n(): int {
		return max( 1, (int) get_option( 'akb_rewards_review_every_n', 1 ) );
	}

	/**
	 * Máximo de premios de reseña por cliente EN LA HISTORIA. 0 = sin tope.
	 *
	 * @return int
	 */
	public static function review_max_per_user(): int {
		return max( 0, (int) get_option( 'akb_rewards_review_max_per_user', 0 ) );
	}

	/**
	 * Mínimo de reseñas elegibles para que el carrusel del home aparezca.
	 *
	 * Decisión del dueño: «cuando sobrepase las 7» → 8 por defecto.
	 *
	 * @return int
	 */
	public static function carousel_min(): int {
		return max( 1, (int) get_option( 'akb_rewards_carousel_min', 8 ) );
	}

	/**
	 * ¿Avisar al dueño cuando algo del programa necesita revisión?
	 *
	 * @return bool
	 */
	public static function alerts_on(): bool {
		return 'yes' === (string) get_option( 'akb_rewards_alerts_enabled', 'yes' );
	}

	/**
	 * Destinatarios de las alertas: los del panel o, si está vacío, el correo
	 * de administración del sitio.
	 *
	 * @return array<int,string>
	 */
	public static function alerts_recipients(): array {
		$lista = self::email_list( (string) get_option( 'akb_rewards_alerts_email', '' ) );

		return array() !== $lista ? $lista : self::email_list( (string) get_option( 'admin_email', '' ) );
	}

	/**
	 * Correos válidos de un texto separado por comas, puntos y comas o espacios.
	 *
	 * La MISMA lectura para guardar y para enviar: lo que el panel acepta es
	 * exactamente lo que recibe el aviso.
	 *
	 * @param string            $texto       Lo escrito.
	 * @param array<int,string> $descartados Por referencia: lo que no era un correo.
	 * @return array<int,string> Correos válidos, sin repetir, en su orden.
	 */
	public static function email_list( string $texto, array &$descartados = array() ): array {
		$validos = array();

		foreach ( (array) preg_split( '/[\s,;]+/', $texto, -1, PREG_SPLIT_NO_EMPTY ) as $pieza ) {
			$correo = sanitize_email( (string) $pieza );
			if ( '' === $correo || false === strpos( $correo, '@' ) ) {
				$descartados[] = (string) $pieza;
				continue;
			}
			if ( ! in_array( $correo, $validos, true ) ) {
				$validos[] = $correo;
			}
		}

		return $validos;
	}
}
