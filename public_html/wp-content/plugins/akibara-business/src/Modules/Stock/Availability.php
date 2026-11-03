<?php
/**
 * Define el estado comercial de los productos a partir del stock de WooCommerce.
 *
 * Disponible corresponde a instock en bodega; A pedido a onbackorder de obra
 * publicada que se trae del proveedor; Preventa a onbackorder con lanzamiento
 * futuro o tba confirmado; Agotado a outofstock no comprable.
 *
 * Una release_date vencida pasa a A pedido al leer. El día de lanzamiento aún
 * cuenta como preventa y el cambio ocurre al día siguiente, con el mismo corte
 * de DateCalculator. StockStatus representa los tres estados crudos y Availability
 * la clasificación comercial y sus etiquetas. Las superficies consumen esa
 * proyección sin reimplementar la distinción. Las claves ETA canónicas residen
 * en Stock y EstimatedDelivery las referencia, sin dependencia inversa.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Stock;

use Akibara\Business\Utilities\StoreTime;
use Akibara\Business\Utilities\Text;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Resolvedor puro del estado comercial. Estático, sin hooks, testeable directo
 * (mismo carácter que StockStatus). El reloj es inyectable por parámetro para
 * que los tests de la degradación sean deterministas.
 *
 * @since 1.109.0
 */
final class Availability {

	/* --- Estados comerciales (el vocabulario interno, NUNCA el visible) --- */

	public const DISPONIBLE = 'disponible';
	public const A_PEDIDO   = 'a_pedido';
	public const PREVENTA   = 'preventa';
	public const AGOTADO    = 'agotado';

	/* --- Meta keys canónicos del panel «Disponibilidad» de la ficha --- */

	/**
	 * Tipo configurado por el dueño. Valores: TYPE_AUTO (default: a pedido,
	 * hereda la política de su editorial) | TYPE_RELEASE_DATE (preventa con
	 * fecha) | TYPE_TBA (preventa sin fecha confirmada) | TYPE_DISABLED
	 * (reservable sin promesa de fecha — sigue siendo A_PEDIDO).
	 */
	public const META_TYPE = '_akb_eta_type';

	/** Fecha de lanzamiento editorial (Y-m-d). Solo aplica con TYPE_RELEASE_DATE. */
	public const META_RELEASE_DATE = '_akb_eta_release_date';

	public const TYPE_AUTO         = 'auto';
	public const TYPE_RELEASE_DATE = 'release_date';
	public const TYPE_TBA          = 'tba';
	public const TYPE_DISABLED     = 'disabled';

	/**
	 * Vocabulario canónico de META_TYPE.
	 *
	 * sanitize_type() interpreta todos los valores admitidos. Los consumidores y
	 * snapshots de pedido usan esa declaración compartida, sin duplicar literales.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = array(
		self::TYPE_AUTO,
		self::TYPE_RELEASE_DATE,
		self::TYPE_TBA,
		self::TYPE_DISABLED,
	);

	/**
	 * Los dos tipos que SÍ son preventa: hay un lanzamiento que todavía no
	 * ocurrió. Subconjunto declarado acá por la misma razón que TYPES — el
	 * cálculo de fechas lo enumeraba a mano.
	 *
	 * @var array<int, string>
	 */
	public const TYPES_PREVENTA = array(
		self::TYPE_RELEASE_DATE,
		self::TYPE_TBA,
	);

	/**
	 * Normaliza un META_TYPE leído de la base.
	 *
	 * Cualquier valor fuera del vocabulario —incluido el '' del producto que
	 * nunca pasó por el panel— cae a TYPE_AUTO, que es el default del negocio:
	 * a pedido, heredando la política de su editorial.
	 *
	 * @param string $type Valor crudo.
	 * @return string Uno de TYPES.
	 */
	public static function sanitize_type( string $type ): string {
		return in_array( $type, self::TYPES, true ) ? $type : self::TYPE_AUTO;
	}

	/**
	 * Vocabulario visible del estado comercial — la FUENTE ÚNICA de la palabra.
	 *
	 * Mismo contrato que tenía StockStatus::LABELS (que este mapa reemplaza como
	 * fuente de presentación): solo la palabra, nada de color/tono/ícono.
	 *
	 * @var array<string, string>
	 */
	public const LABELS = array(
		self::DISPONIBLE => 'Disponible',
		self::A_PEDIDO   => 'A pedido',
		self::PREVENTA   => 'Preventa',
		self::AGOTADO    => 'Agotado',
	);

	/**
	 * Los dos estados cuya palabra edita el dueño en Akibara → Textos.
	 *
	 * Eran dos vocabularios para lo mismo: el dueño cambiaba «A pedido» por
	 * «Por encargo» y el cartel de la ficha, el carrito, la línea del pedido y
	 * los correos obedecían —leen estas mismas options— pero el badge de la
	 * tarjeta, el botón de agregar y los chips de la landing seguían diciendo
	 * «A pedido», porque salían del `const` de arriba. Desde 1.116.20 el `const`
	 * es SÓLO el valor de fábrica y la palabra vigente sale de aquí.
	 *
	 * @var array<string, string>
	 */
	private const COPY_OPTIONS = array(
		self::A_PEDIDO => 'akb_copy_eta_single_pedido',
		self::PREVENTA => 'akb_copy_eta_single_preventa',
	);

	/**
	 * Tope de la palabra, en caracteres.
	 *
	 * Es una PALABRA —«A pedido», «Por encargo»—, no una frase: entra en un
	 * badge de tarjeta, en un chip y en un botón. Y viaja además al buscador,
	 * que es otro plugin y otro riel: `Search\StockLabels::label()` LANZA una
	 * excepción si lo que le devuelve el proveedor pasa de 100 bytes, y quien la
	 * come es el autocomplete (descarta la tarjeta) y el indexador (no publica
	 * el documento), los dos en silencio. Con 24 caracteres no se llega a 100
	 * bytes ni escribiéndolos todos con tilde.
	 */
	public const MAX_PALABRA = 24;

	/**
	 * Palabra VIGENTE de un estado: la del panel si el dueño la cambió, si no
	 * la de fábrica. Sin filtros — es el insumo de `label_of()` y de quien
	 * responde el filtro, que no puede volver a aplicarlo sin recursión.
	 *
	 * @param string $state Estado comercial.
	 * @return string
	 */
	public static function palabra( string $state ): string {
		$fabrica = self::LABELS[ $state ] ?? self::LABELS[ self::AGOTADO ];

		if ( ! isset( self::COPY_OPTIONS[ $state ] ) || ! function_exists( 'get_option' ) ) {
			return $fabrica;
		}

		// Se sanea AL LEER, no sólo al guardar: la option también puede llegar
		// por `wp option update` o por una importación, que no pasan por ningún
		// formulario. Fuera de tope vuelve el valor de fábrica, que es lo que
		// deja la tienda coherente en vez de a medias.
		$suya = Text::one_line( get_option( self::COPY_OPTIONS[ $state ], $fabrica ), $fabrica );

		if ( '' === $suya || Text::length( $suya ) > self::MAX_PALABRA ) {
			return $fabrica;
		}

		return $suya;
	}

	/**
	 * Estado comercial del producto.
	 *
	 * @param WC_Product $product Producto Woo.
	 * @param int|null   $now     Reloj inyectable (tests). Default: time().
	 * @return string Uno de DISPONIBLE|A_PEDIDO|PREVENTA|AGOTADO.
	 */
	public static function of( WC_Product $product, ?int $now = null ): string {
		$stock = StockStatus::of( $product );

		if ( StockStatus::IN_STOCK === $stock ) {
			return self::DISPONIBLE;
		}
		if ( StockStatus::ON_BACKORDER === $stock ) {
			return self::pending_launch( $product, $now ) ? self::PREVENTA : self::A_PEDIDO;
		}

		// outofstock y cualquier estado desconocido caen acá: fail-closed al
		// estado más conservador, igual que StockStatus::label().
		return self::AGOTADO;
	}

	/**
	 * Palabra visible del estado comercial — la ÚNICA vía de presentación.
	 *
	 * El filtro `akb_availability_label` es el punto de override del vocabulario
	 * (heredero de `akb_stock_status_label`, que solo conocía el stock crudo y
	 * no podía distinguir a-pedido de preventa).
	 *
	 * @param WC_Product $product Producto Woo.
	 * @param int|null   $now     Reloj inyectable (tests).
	 * @return string Palabra visible.
	 */
	public static function label_of( WC_Product $product, ?int $now = null ): string {
		$state = self::of( $product, $now );
		$label = self::palabra( $state );

		/**
		 * Filtra la palabra visible de un estado comercial. Devolvé la palabra a
		 * mostrar para ese estado.
		 *
		 * @since 1.109.0
		 *
		 * @param string $label Palabra resuelta.
		 * @param string $state Estado comercial resuelto (constante de esta clase).
		 */
		return (string) apply_filters(
			'akb_availability_label',
			$label,
			$state
		);
	}

	/**
	 * Palabra visible de un estado ya resuelto (para quien indexó el estado y
	 * no tiene el WC_Product a mano, p. ej. el buscador al pintar facetas).
	 *
	 * @param string $state Estado comercial.
	 * @return string
	 */
	public static function label_for_state( string $state ): string {
		$label = self::palabra( $state );

		/** Documentado en label_of(). */
		return (string) apply_filters(
			'akb_availability_label',
			$label,
			$state
		);
	}

	/**
	 * ¿Es una preventa REAL? (todavía no sale: fecha futura o TBA).
	 *
	 * OJO: no confundir con el viejo StockStatus::is_preventa (retirado), que en
	 * verdad preguntaba «¿es reservable?» (onbackorder) y hoy se llama
	 * StockStatus::is_reservable().
	 *
	 * @param WC_Product $product Producto Woo.
	 * @param int|null   $now     Reloj inyectable (tests).
	 */
	public static function is_preventa( WC_Product $product, ?int $now = null ): bool {
		return self::PREVENTA === self::of( $product, $now );
	}

	/**
	 * ¿Es un pedido a editorial? (ya publicado; se trae en el próximo corte).
	 *
	 * @param WC_Product $product Producto Woo.
	 * @param int|null   $now     Reloj inyectable (tests).
	 */
	public static function is_a_pedido( WC_Product $product, ?int $now = null ): bool {
		return self::A_PEDIDO === self::of( $product, $now );
	}

	/**
	 * ¿Tiene un lanzamiento pendiente? (lo que separa PREVENTA de A_PEDIDO).
	 *
	 * - TYPE_TBA: la editorial confirmó el título pero no la fecha → pendiente.
	 * - TYPE_RELEASE_DATE con fecha hoy o futura → pendiente.
	 * - TYPE_RELEASE_DATE con fecha pasada, vacía o corrupta → NO pendiente:
	 *   el tomo ya salió (o no hay forma de sostener que no), así que es un
	 *   pedido. Fecha corrupta degrada en silencio aquí porque DateCalculator
	 *   ya la reporta a Log al calcular la ETA — dos avisos por la misma fila
	 *   serían ruido.
	 * - TYPE_AUTO / TYPE_DISABLED / valor desconocido → NO pendiente (default).
	 *
	 * @param WC_Product $product Producto Woo.
	 * @param int|null   $now     Reloj inyectable (tests).
	 */
	private static function pending_launch( WC_Product $product, ?int $now ): bool {
		// get_post_meta (no WC_Product::get_meta) por la misma razón medida en
		// DateCalculator::resolve_type(): lee del meta-cache que el loop primó;
		// el canal WC_Data pagaba +1 query por card en los archives.
		$type = (string) get_post_meta( $product->get_id(), self::META_TYPE, true );

		if ( self::TYPE_TBA === $type ) {
			return true;
		}
		if ( self::TYPE_RELEASE_DATE !== $type ) {
			return false;
		}

		$tz         = StoreTime::zone();
		$raw        = get_post_meta( $product->get_id(), self::META_RELEASE_DATE, true );
		$release_ts = self::release_timestamp( is_string( $raw ) ? $raw : '', $tz );

		// Fecha ausente o corrupta degrada en silencio ACÁ porque DateCalculator
		// ya la reporta a Log al calcular la ETA — dos avisos por la misma fila
		// serían ruido.
		if ( null === $release_ts ) {
			return false;
		}

		$today = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )
			->setTimezone( $tz )
			->setTime( 0, 0, 0 );

		// Mismo corte que DateCalculator::resolve_base_timestamp(): el día del
		// lanzamiento sigue siendo preventa; degrada al día siguiente.
		return $release_ts >= $today->getTimestamp();
	}

	/**
	 * Cota SUPERIOR aceptable para `release_date`, en años desde hoy. Existe
	 * porque un typo de año (2206 en vez de 2026) pasaba `preg_match` +
	 * `checkdate` y se persistía: de ahí lo lee el schema de producto y lo
	 * congela el snapshot de la línea del pedido — un dato absurdo se propagaba
	 * a Google, a órdenes históricas y, desde la pantalla «Por llegar», a un
	 * correo irreversible al cliente. El `max` de un `<input type="date">` NO es
	 * defensa: el servidor recibe cualquier POST.
	 *
	 * NO existe cota inferior a propósito: rechazar fechas pasadas haría que
	 * re-guardar un producto ya lanzado borre en silencio un dato que otros
	 * módulos leen. El `min` de la UI es ayuda de captura, no invariante.
	 *
	 * @since 1.114.12 Antes constante privada de ProductDataPanel; la pantalla
	 *                 «Por llegar» escribía la misma meta sin poder consultarla.
	 */
	public const MAX_RELEASE_DATE_YEARS = 5;

	/**
	 * Cota superior aceptable para `release_date` (`Y-m-d`). Fuente única del
	 * `max` de los inputs y del guard de `is_valid_release_date()`.
	 *
	 * @param string|null $today `Y-m-d` base (tests). null = ahora en la TZ del sitio.
	 * @return string
	 */
	public static function max_release_date( ?string $today = null ): string {
		$tz   = StoreTime::zone();
		$base = null;

		if ( null !== $today && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) {
			// Mediodía: evita off-by-one por DST al sumar años.
			$base = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $today . ' 12:00:00', $tz );
		}
		if ( ! $base instanceof \DateTimeImmutable ) {
			$base = new \DateTimeImmutable( 'now', $tz );
		}

		return $base->modify( '+' . self::MAX_RELEASE_DATE_YEARS . ' years' )->format( 'Y-m-d' );
	}

	/**
	 * ¿Es una fecha de lanzamiento aceptable? `Y-m-d`, día real del calendario
	 * y no más de MAX_RELEASE_DATE_YEARS en el futuro.
	 *
	 * La ÚNICA regla: la llaman la ficha del producto, la pantalla «Por llegar» y
	 * el schema de producto. Hasta 1.114.11 la ficha validaba y la pantalla no,
	 * y el dueño no tenía forma de saber que una puerta rechazaba lo que la otra
	 * aceptaba.
	 *
	 * @since 1.114.12
	 *
	 * @param string      $ymd   Fecha cruda.
	 * @param string|null $today `Y-m-d` base de la cota (tests). null = hoy.
	 * @return bool
	 */
	public static function is_valid_release_date( string $ymd, ?string $today = null ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return false;
		}
		$parts = explode( '-', $ymd );
		if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
			return false;
		}
		// Comparación lexicográfica válida: ambos son `Y-m-d` de ancho fijo.
		return $ymd <= self::max_release_date( $today );
	}

	/**
	 * Intérprete ÚNICO de la fecha de lanzamiento: `Y-m-d` crudo → medianoche
	 * local, o null si no hay fecha o PHP no la sabe leer.
	 *
	 * Vive acá porque `META_RELEASE_DATE` es de esta clase. Antes lo hacían por
	 * separado esta misma clase y `DateCalculator::resolve_base_timestamp()`, y
	 * la duplicación NO era inocente: las dos copias interpretaban igual pero
	 * manejaban el error distinto, así que un formato que una degradaba en
	 * silencio la otra podía haberlo aceptado. Lo que SÍ sigue siendo de cada
	 * llamador es qué hacer con el null: acá se degrada callado y allá se deja
	 * traza, y ese reparto es deliberado.
	 *
	 * @param string        $raw Valor crudo de la meta.
	 * @param \DateTimeZone $tz  Zona de la tienda.
	 * @return int|null Medianoche local de la fecha, o null.
	 */
	public static function release_timestamp( string $raw, \DateTimeZone $tz ): ?int {
		if ( '' === $raw ) {
			return null;
		}

		try {
			$release = new \DateTimeImmutable( $raw, $tz );
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}

		return $release->setTime( 0, 0, 0 )->getTimestamp();
	}
}
