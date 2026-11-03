<?php
/**
 * Política por editorial con herencia desde el país. Las editoriales de un
 * mismo país pueden tener cadencias distintas; el término de marca permite
 * configurar sus cierres y plazos independientemente.
 * El modo inicial es heredado. Activar una tanda supone una decisión logística
 * de consolidación: la promesa se ancla al cierre y añade desde allí el plazo
 * configurado, en vez de asumir viaje inmediato desde la compra.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EstimatedDelivery;

use Akibara\Business\Utilities\StoreTime;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve qué política de preventa aplica a un producto.
 *
 * @since 1.108.22
 */
final class PedidoPolicy {

	public const TAXONOMY = 'product_brand';

	/** Modo de la editorial. */
	public const META_MODO = '_akb_pedido_modo';

	/** Día del mes en que cierra la tanda (1-28). */
	public const META_DIA = '_akb_tanda_dia';

	/** Frecuencia o cadencia de la tanda (mensual, bimestral, etc.). */
	public const META_FRECUENCIA = '_akb_tanda_frecuencia';

	/** Meses activos para frecuencia personalizada (separados por coma: "1,3,7,11"). */
	public const META_MESES = '_akb_tanda_meses';

	/** Días CORRIDOS entre el cierre de la tanda y la llegada. */
	public const META_MIN = '_akb_tanda_min_dias';
	public const META_MAX = '_akb_tanda_max_dias';

	/** Opciones canónicas de frecuencia para tandas. */
	public const FRECUENCIA_QUINCENAL        = 'quincenal';        // 2 cortes al mes (ej: día 5 y día 20)
	public const FRECUENCIA_MENSUAL          = 'mensual';          // Todos los meses
	public const FRECUENCIA_BIMESTRAL_IMPAR  = 'bimestral_impar';  // Ene, Mar, May, Jul, Sep, Nov
	public const FRECUENCIA_BIMESTRAL_PAR    = 'bimestral_par';    // Feb, Abr, Jun, Ago, Oct, Dic
	public const FRECUENCIA_TRIMESTRAL_ENE   = 'trimestral_ene';   // Ene, Abr, Jul, Oct
	public const FRECUENCIA_TRIMESTRAL_MAR   = 'trimestral_mar';   // Mar, Jun, Sep, Dic
	public const FRECUENCIA_PERSONALIZADA    = 'personalizada';    // Meses a elección libre

	/** Sin configurar: se comporta como siempre (plazo del país desde la compra). */
	public const MODO_HEREDADO = 'heredado';

	/** Explícitamente continuo: el plazo corre desde la compra. */
	public const MODO_CONTINUO = 'continuo';

	/** Por tanda: el plazo corre desde el cierre. */
	public const MODO_TANDA = 'tanda';

	/**
	 * La editorial no acepta preventas ahora mismo.
	 *
	 * Existe para el caso Panini Argentina: 372 productos en catálogo y una sola
	 * preventa viva. Cuando una distribuidora deja de ser fiable, lo honesto no es
	 * ajustar el plazo sino dejar de prometer fecha. Este modo fuerza «sin fecha»
	 * en vez de heredar una promesa que nadie puede sostener.
	 */
	public const MODO_PAUSADA = 'pausada';

	/** @var array<int, array<string,mixed>> Memo por producto dentro del request. */
	private array $memo = array();

	/**
	 * Tope del día de cierre.
	 *
	 * 28 y no 31 a propósito: un corte «el 30» no existe en febrero, y la regla de
	 * recorte silencioso («si el mes no llega, el último día») convierte una
	 * promesa en una excepción que nadie recuerda al leer el panel. Con 1-28 el
	 * día elegido existe en todos los meses del año.
	 */
	public const DIA_MAXIMO = 28;

	/**
	 * Días corridos dentro de los cuales el cierre deja de ser un dato y pasa a
	 * ser un plazo: a partir de aquí la ficha y la grilla dicen cuántos quedan.
	 *
	 * Siete y no treinta porque el aviso tiene que significar algo. Con la tanda
	 * mensual, un contador que corre todo el mes es el estado normal del producto
	 * —o sea, ruido— y cuando de verdad quedan dos días ya nadie lo mira.
	 */
	public const DIAS_DE_AVISO = 7;

	/**
	 * Tabla única de meses en español y minúscula, consultada mediante mes().
	 * Las abreviaturas de BannerRenderer mantienen un contrato separado.
	 *
	 * @var array<int, string>
	 */
	private const MESES_ES = array(
		1 => 'enero',
		'febrero',
		'marzo',
		'abril',
		'mayo',
		'junio',
		'julio',
		'agosto',
		'septiembre',
		'octubre',
		'noviembre',
		'diciembre',
	);

	/**
	 * Vocabulario de frecuencias de tanda con sus etiquetas para la UI.
	 *
	 * @return array<string, string>
	 */
	public static function opciones_frecuencia(): array {
		return array(
			self::FRECUENCIA_QUINCENAL       => 'Quincenal (2 cortes al mes)',
			self::FRECUENCIA_MENSUAL         => 'Mensual (todos los meses)',
			self::FRECUENCIA_BIMESTRAL_IMPAR => 'Bimestral (meses impares: Ene, Mar, May, Jul, Sep, Nov)',
			self::FRECUENCIA_BIMESTRAL_PAR   => 'Bimestral (meses pares: Feb, Abr, Jun, Ago, Oct, Dic)',
			self::FRECUENCIA_TRIMESTRAL_ENE  => 'Trimestral (Ene, Abr, Jul, Oct)',
			self::FRECUENCIA_TRIMESTRAL_MAR  => 'Trimestral (Mar, Jun, Sep, Dic)',
			self::FRECUENCIA_PERSONALIZADA   => 'Personalizada (meses a elección)',
		);
	}

	/**
	 * Meses del año (1-12) en los que opera una frecuencia determinada.
	 *
	 * @param string $frecuencia Clave de frecuencia.
	 * @param string $meses_raw  Lista de meses si la frecuencia es personalizada.
	 * @return array<int, int>
	 */
	public static function meses_de_frecuencia( string $frecuencia, string $meses_raw = '' ): array {
		if ( self::FRECUENCIA_PERSONALIZADA === $frecuencia && '' !== $meses_raw ) {
			$meses = array_filter( array_map( 'intval', explode( ',', $meses_raw ) ), static fn( $m ) => $m >= 1 && $m <= 12 );
			if ( ! empty( $meses ) ) {
				sort( $meses );
				return array_values( array_unique( $meses ) );
			}
		}

		switch ( $frecuencia ) {
			case self::FRECUENCIA_BIMESTRAL_IMPAR:
				return array( 1, 3, 5, 7, 9, 11 );
			case self::FRECUENCIA_BIMESTRAL_PAR:
				return array( 2, 4, 6, 8, 10, 12 );
			case self::FRECUENCIA_TRIMESTRAL_ENE:
				return array( 1, 4, 7, 10 );
			case self::FRECUENCIA_TRIMESTRAL_MAR:
				return array( 3, 6, 9, 12 );
			case self::FRECUENCIA_QUINCENAL:
			case self::FRECUENCIA_MENSUAL:
			default:
				if ( false !== strpos( $frecuencia, ',' ) || ( is_numeric( $frecuencia ) && (int) $frecuencia >= 1 && (int) $frecuencia <= 12 ) ) {
					$meses = array_filter( array_map( 'intval', explode( ',', $frecuencia ) ), static fn( $m ) => $m >= 1 && $m <= 12 );
					if ( ! empty( $meses ) ) {
						sort( $meses );
						return array_values( array_unique( $meses ) );
					}
				}
				return array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 );
		}
	}

	/**
	 * Días de cierre del mes (1-28), en orden ascendente.
	 *
	 * Soporta un día único (ej: 20) o múltiples días para corte quincenal (ej: "5, 20").
	 *
	 * @param mixed  $raw        Valor crudo del término (int o string "5, 20").
	 * @param string $frecuencia Frecuencia de la tanda.
	 * @return array<int, int> Lista de días de cierre válidos.
	 */
	public static function dias_de_cierre( $raw, string $frecuencia = self::FRECUENCIA_MENSUAL ): array {
		if ( is_string( $raw ) && false !== strpos( $raw, ',' ) ) {
			$dias = array_filter( array_map( 'intval', explode( ',', $raw ) ), static fn( $d ) => $d >= 1 && $d <= self::DIA_MAXIMO );
			if ( ! empty( $dias ) ) {
				sort( $dias );
				return array_values( array_unique( $dias ) );
			}
		}

		$dia = is_scalar( $raw ) ? (int) $raw : 0;
		$dia = ( $dia >= 1 && $dia <= self::DIA_MAXIMO ) ? $dia : 1;

		if ( self::FRECUENCIA_QUINCENAL === $frecuencia ) {
			$segundo = ( $dia <= 14 ) ? ( $dia + 15 ) : ( $dia - 15 );
			$dias    = array( $dia, $segundo );
			sort( $dias );
			return array_values( array_filter( $dias, static fn( $d ) => $d >= 1 && $d <= self::DIA_MAXIMO ) );
		}

		return array( $dia );
	}

	/**
	 * @return array{modo:string, dia:int, min:int, max:int, frecuencia:string, marca:string}
	 */
	public function for_product( WC_Product $product ): array {
		$id = $product->get_id();
		if ( isset( $this->memo[ $id ] ) ) {
			return $this->memo[ $id ];
		}

		$this->memo[ $id ] = $this->resolve( $product );

		return $this->memo[ $id ];
	}

	/**
	 * @return array{modo:string, dia:mixed, min:int, max:int, frecuencia:string, meses:string, marca:string}
	 */
	private function resolve( WC_Product $product ): array {
		$vacio = array( 'modo' => self::MODO_HEREDADO, 'dia' => 0, 'min' => 0, 'max' => 0, 'frecuencia' => self::FRECUENCIA_MENSUAL, 'meses' => '', 'marca' => '' );

		if ( ! function_exists( 'get_the_terms' ) ) {
			return $vacio;
		}

		// Prioridad 1: EDITORIAL (product_brand).
		$terms = get_the_terms( $product->get_id(), self::TAXONOMY );
		if ( ! ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) && is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
					continue;
				}

				$modo = (string) get_term_meta( (int) $term->term_id, self::META_MODO, true );
				if ( ! in_array( $modo, array( self::MODO_CONTINUO, self::MODO_TANDA, self::MODO_PAUSADA ), true ) ) {
					continue;
				}

				return array(
					'modo'       => $modo,
					'dia'        => $this->dia_valido( get_term_meta( (int) $term->term_id, self::META_DIA, true ) ),
					'min'        => max( 0, (int) get_term_meta( (int) $term->term_id, self::META_MIN, true ) ),
					'max'        => max( 0, (int) get_term_meta( (int) $term->term_id, self::META_MAX, true ) ),
					'frecuencia' => $this->frecuencia_valida( get_term_meta( (int) $term->term_id, self::META_FRECUENCIA, true ) ),
					'meses'      => (string) get_term_meta( (int) $term->term_id, self::META_MESES, true ),
					'marca'      => isset( $term->name ) ? (string) $term->name : '',
				);
			}
		}

		// Fallback por PAÍS
		$paises = get_the_terms( $product->get_id(), 'pa_pais' );
		if ( ! ( function_exists( 'is_wp_error' ) && is_wp_error( $paises ) ) && is_array( $paises ) ) {
			foreach ( $paises as $term ) {
				if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
					continue;
				}

				$modo = (string) get_term_meta( (int) $term->term_id, self::META_MODO, true );
				if ( ! in_array( $modo, array( self::MODO_CONTINUO, self::MODO_TANDA, self::MODO_PAUSADA ), true ) ) {
					continue;
				}

				return array(
					'modo'       => $modo,
					'dia'        => $this->dia_valido( get_term_meta( (int) $term->term_id, self::META_DIA, true ) ),
					'min'        => max( 0, (int) get_term_meta( (int) $term->term_id, self::META_MIN, true ) ),
					'max'        => max( 0, (int) get_term_meta( (int) $term->term_id, self::META_MAX, true ) ),
					'frecuencia' => $this->frecuencia_valida( get_term_meta( (int) $term->term_id, self::META_FRECUENCIA, true ) ),
					'meses'      => (string) get_term_meta( (int) $term->term_id, self::META_MESES, true ),
					'marca'      => isset( $term->name ) ? (string) $term->name : '',
				);
			}
		}

		return $vacio;
	}

	/**
	 * Frecuencia saneada o FRECUENCIA_MENSUAL si es inválida o vacía.
	 *
	 * @param mixed $raw Valor crudo de term-meta.
	 * @return string
	 */
	private function frecuencia_valida( $raw ): string {
		$frecuencia = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		$validas    = array_keys( self::opciones_frecuencia() );

		return in_array( $frecuencia, $validas, true ) ? $frecuencia : self::FRECUENCIA_MENSUAL;
	}

	/**
	 * Día o días de cierre saneados (int o string "5, 20"), o 0 si no es utilizable.
	 *
	 * @param mixed $raw
	 * @return int|string
	 */
	private function dia_valido( $raw ) {
		if ( is_string( $raw ) && false !== strpos( $raw, ',' ) ) {
			$partes = array_filter( array_map( 'intval', explode( ',', $raw ) ), static fn( $d ) => $d >= 1 && $d <= self::DIA_MAXIMO );
			if ( ! empty( $partes ) ) {
				sort( $partes );
				return implode( ', ', array_unique( $partes ) );
			}
		}

		$dia = absint( $raw );

		return ( $dia >= 1 && $dia <= self::DIA_MAXIMO ) ? $dia : 0;
	}

	/**
	 * Primer cierre de tanda en o después de `$desde` considerando la frecuencia de corte.
	 *
	 * `$desde` es lo más tardío entre hoy y la fecha de lanzamiento del tomo: un
	 * título que sale el 20 NO puede entrar en el corte del 5 de ese mes, porque
	 * el día del cierre la mercadería todavía no existe. Prometerlo sería la
	 * versión elegante de mentir.
	 *
	 * Soporta cortes quincenales (múltiples días en el mes) y meses personalizados.
	 *
	 * @param int           $desde      Marca de tiempo desde la que buscar.
	 * @param mixed         $dia        Día(s) del mes del cierre (int 1-28 o string "5, 20").
	 * @param \DateTimeZone $tz         Zona horaria de la tienda.
	 * @param string        $frecuencia Cadencia (quincenal, mensual, bimestral, personalizada).
	 * @param string        $meses_raw  Lista de meses si la frecuencia es personalizada.
	 * @return int Marca de tiempo del cierre, a medianoche local.
	 */
	public function proximo_cierre( int $desde, $dia, \DateTimeZone $tz, string $frecuencia = self::FRECUENCIA_MENSUAL, string $meses_raw = '' ): int {
		$dias_permitidos  = self::dias_de_cierre( $dia, $frecuencia );
		$meses_permitidos = self::meses_de_frecuencia( $frecuencia, $meses_raw );

		$cursor = ( new \DateTimeImmutable( '@' . $desde ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
		$hoy    = $cursor;

		for ( $i = 0; $i < 24; $i++ ) {
			$mes = (int) $cursor->format( 'n' );
			if ( in_array( $mes, $meses_permitidos, true ) ) {
				foreach ( $dias_permitidos as $d ) {
					$cierre = $cursor->modify( 'first day of this month' )->modify( '+' . ( $d - 1 ) . ' days' );
					if ( $cierre >= $hoy ) {
						return $cierre->getTimestamp();
					}
				}
			}
			$cursor = $cursor->modify( 'first day of next month' );
		}

		return $hoy->getTimestamp();
	}

	/**
	 * Frase que explica al cliente en qué tanda entra su compra.
	 *
	 * @param array{modo:string, dia:mixed, min:int, max:int, frecuencia:string, meses:string, marca:string} $policy
	 */
	public function frase_de_tanda( array $policy, int $desde, \DateTimeZone $tz ): string {
		if ( ! self::tanda_utilizable( $policy ) ) {
			return '';
		}

		$cierre = $this->proximo_cierre( $desde, $policy['dia'], $tz, (string) ( $policy['frecuencia'] ?? '' ), (string) ( $policy['meses'] ?? '' ) );

		return sprintf(
			'Sale en el pedido que cierra el %s; desde ahí viaja %s.',
			self::dia_y_mes( $cierre, $tz ),
			self::semanas_de_tanda( (int) ( $policy['min'] ?? 0 ), (int) ( $policy['max'] ?? 0 ) )
		);
	}

	/**
	 * Aviso de urgencia cuando el cierre está a la vuelta de la esquina.
	 *
	 * Sale del MISMO `proximo_cierre()` que produce la fecha del banner: la
	 * aritmética de cortes vive en un solo sitio y este método sólo la lee. Una
	 * segunda cuenta «equivalente» es lo que deja la ficha diciendo que quedan
	 * dos días mientras el rango ya saltó al mes siguiente.
	 *
	 * Fuera de la ventana devuelve '' — y también pasado el cierre, que en la
	 * práctica no ocurre: `proximo_cierre()` nunca devuelve una fecha anterior a
	 * su ancla, así que el día 6 la cuenta ya apunta al corte del mes siguiente y
	 * el aviso simplemente desaparece hasta que ese otro se acerque.
	 *
	 * @param array{modo:string, dia:mixed, min:int, max:int, frecuencia?:string, meses?:string, marca:string} $policy   Política vigente.
	 * @param int                                                                             $desde    Ancla del cálculo (la misma del banner).
	 * @param \DateTimeZone                                                                   $tz       Zona de la tienda.
	 * @param int                                                                             $ahora    Marca de tiempo de «hoy».
	 * @param bool                                                                            $compacta Versión sin la fecha, para la píldora de la grilla.
	 */
	public function frase_de_urgencia( array $policy, int $desde, \DateTimeZone $tz, int $ahora, bool $compacta = false ): string {
		if ( ! self::tanda_utilizable( $policy ) ) {
			return '';
		}

		$cierre = $this->proximo_cierre( $desde, $policy['dia'], $tz, (string) ( $policy['frecuencia'] ?? '' ), (string) ( $policy['meses'] ?? '' ) );
		$dias   = self::dias_corridos_hasta( $ahora, $cierre, $tz );

		if ( $dias < 0 || $dias > self::DIAS_DE_AVISO ) {
			return '';
		}

		// La grilla repite esta línea en CADA tarjeta —y en /a-pedido/ todas
		// comparten origen, así que serían 24 copias de la misma oración—. Ahí el
		// dato que falta es la urgencia, no la fecha: el corte ya está nombrado en
		// la ficha, a un clic. El texto lo produce este mismo método para que las
		// dos versiones no puedan divergir.
		$cierre_texto = $compacta ? '' : ' en el pedido del ' . self::dia_y_mes( $cierre, $tz );

		if ( 0 === $dias ) {
			return $compacta
				? 'Hoy es el último día para entrar'
				: 'Hoy es el último día para entrar' . $cierre_texto;
		}

		if ( 1 === $dias ) {
			return 'Queda 1 día para entrar' . $cierre_texto;
		}

		return sprintf( 'Quedan %d días para entrar%s', $dias, $cierre_texto );
	}

	/**
	 * Instante desde el que hay que buscar el cierre que produjo ESTA fecha.
	 *
	 * El cierre se busca desde el MISMO instante que ancló el cálculo, no desde
	 * `time()`. Con un tomo cuyo lanzamiento es futuro, `calculate()` parte de la
	 * fecha de lanzamiento y encuentra un cierre posterior; una nota anclada en
	 * hoy nombraría un cierre ANTERIOR al que produjo la fecha impresa justo
	 * encima, y las dos superficies se contradirían.
	 *
	 * `min_ts()` es el cierre MÁS los días mínimos, así que retroceder ese plazo
	 * devuelve el cierre exacto; buscar «el próximo cierre desde ahí» sobre esa
	 * misma fecha lo encuentra sin desplazarse. La invariante está fijada en
	 * `DateCalculatorTandaTest::test_the_note_names_the_same_cutoff…`.
	 *
	 * Vive aquí y no en quien pinta: la aritmética de cortes tiene un solo sitio,
	 * y desde que el correo de «Sigue esta serie» también nombra el cierre, la
	 * alternativa era una segunda cuenta equivalente en otro módulo.
	 *
	 * @param EtaResult           $eta    Resultado con fecha.
	 * @param array<string,mixed> $policy Política resuelta del producto.
	 */
	public static function ancla_de_tanda( EtaResult $eta, array $policy ): ?int {
		$desde = $eta->min_ts();
		if ( null === $desde ) {
			return null;
		}

		return $desde - ( (int) ( $policy['min'] ?? 0 ) * DAY_IN_SECONDS );
	}

	/**
	 * Traduce el plazo de la tanda a semanas, que es la unidad en la que el
	 * cliente piensa una espera larga («un mes y algo», no «35 días»).
	 *
	 * El clamp inferior a 1 no es cosmético: `round(3/7)` es 0 y «viaja 0
	 * semanas» convertiría una promesa conservadora en una imposible. El superior
	 * sólo garantiza que el rango no salga invertido si alguien guardó un máximo
	 * menor que el mínimo por una vía que no pase por `sanitize_row()`.
	 *
	 * @param int $min Días corridos mínimos desde el cierre.
	 * @param int $max Días corridos máximos desde el cierre.
	 */
	public static function semanas_de_tanda( int $min, int $max ): string {
		$desde = max( 1, (int) round( max( 0, $min ) / 7 ) );
		$hasta = max( $desde, (int) round( max( 0, $max ) / 7 ) );

		if ( $desde === $hasta ) {
			return 1 === $desde ? '~1 semana' : sprintf( '~%d semanas', $desde );
		}

		return sprintf( '%d–%d semanas', $desde, $hasta );
	}

	/**
	 * Días CORRIDOS entre dos instantes, contados como días de calendario en la
	 * zona de la tienda.
	 *
	 * Días de calendario y no `(b - a) / 86400`: en Chile hay días de 23 y de 25
	 * horas dos veces al año, y con aritmética de segundos «quedan 2 días» se
	 * convierte en «queda 1» la mañana del cambio de hora.
	 *
	 * @param int           $desde Instante de partida.
	 * @param int           $hasta Instante de llegada.
	 * @param \DateTimeZone $tz    Zona de la tienda.
	 */
	public static function dias_corridos_hasta( int $desde, int $hasta, \DateTimeZone $tz ): int {
		$a = ( new \DateTimeImmutable( '@' . $desde ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
		$b = ( new \DateTimeImmutable( '@' . $hasta ) )->setTimezone( $tz )->setTime( 0, 0, 0 );

		$diff = $a->diff( $b );
		$dias = (int) $diff->days;

		return 1 === $diff->invert ? -$dias : $dias;
	}

	/**
	 * La MISMA condición que usa DateCalculator para aplicar el corte. Con
	 * condiciones distintas, un término a medio configurar (modo y día sin
	 * plazos) daría una ficha que promete una tanda mientras la fecha viene del
	 * plazo del país: la contradicción más difícil de ver, porque las dos mitades
	 * se leen correctas por separado.
	 *
	 * @param array<string,mixed> $policy Política resuelta.
	 */
	/**
	 * La MISMA condición que usa DateCalculator para aplicar el corte. Con
	 * condiciones distintas, un término a medio configurar (modo y día sin
	 * plazos) daría una ficha que promete una tanda mientras la fecha viene del
	 * plazo del país: la contradicción más difícil de ver, porque las dos mitades
	 * se leen correctas por separado.
	 *
	 * @param array<string,mixed> $policy Política resuelta.
	 */
	public static function tanda_utilizable( array $policy ): bool {
		if ( self::MODO_TANDA !== ( $policy['modo'] ?? '' ) || (int) ( $policy['max'] ?? 0 ) < 1 ) {
			return false;
		}

		$dia = $policy['dia'] ?? 0;
		if ( is_string( $dia ) && false !== strpos( $dia, ',' ) ) {
			$partes = array_filter( array_map( 'intval', explode( ',', $dia ) ), static fn( $d ) => $d >= 1 && $d <= self::DIA_MAXIMO );
			return ! empty( $partes );
		}

		$dia_int = is_scalar( $dia ) ? (int) $dia : 0;
		return $dia_int >= 1 && $dia_int <= self::DIA_MAXIMO;
	}

	/**
	 * Nombre completo del mes en español, en minúscula — la ÚNICA vía de lectura
	 * de la tabla, para toda la casa.
	 *
	 * @param int $mes Mes 1-12.
	 * @return string Nombre del mes, o '' si el número está fuera de rango (el
	 *                '' es también el predicado de validez que usan los
	 *                llamadores que antes hacían `isset()` sobre su copia).
	 */
	public static function mes( int $mes ): string {
		return self::MESES_ES[ $mes ] ?? '';
	}

	/**
	 * «5 de septiembre» para una marca de tiempo, en la zona de la tienda.
	 *
	 * @param int           $ts Marca de tiempo del cierre.
	 * @param \DateTimeZone $tz Zona de la tienda.
	 */
	private static function dia_y_mes( int $ts, \DateTimeZone $tz ): string {
		$fecha = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );

		return sprintf(
			'%d de %s',
			(int) $fecha->format( 'j' ),
			self::mes( (int) $fecha->format( 'n' ) )
		);
	}

	/**
	 * Validador puro compartido por pantallas de términos y tabla de cortes.
	 * Devuelve metas a escribir y borrar. HEREDADO elimina metas; CONTINUO y
	 * PAUSADA sólo cambian el modo, conservando plazos que el calculador ignora
	 * según la precedencia de ese modo.
	 *
	 * @param array{modo?:mixed,dia?:mixed,min?:mixed,max?:mixed,frecuencia?:mixed,meses?:mixed} $raw Entrada cruda.
	 * @return array{ok:bool,error:string,set:array<string,int|string>,delete:array<int,string>}
	 */
	public static function sanitize_row( array $raw ): array {
		$modo       = sanitize_key( (string) ( $raw['modo'] ?? '' ) );
		$min        = absint( $raw['min'] ?? 0 );
		$max        = absint( $raw['max'] ?? 0 );
		$frecuencia = is_scalar( $raw['frecuencia'] ?? '' ) ? strtolower( trim( (string) ( $raw['frecuencia'] ?? '' ) ) ) : '';
		if ( ! in_array( $frecuencia, array_keys( self::opciones_frecuencia() ), true ) ) {
			$frecuencia = self::FRECUENCIA_MENSUAL;
		}

		$validos = array( self::MODO_HEREDADO, self::MODO_CONTINUO, self::MODO_TANDA, self::MODO_PAUSADA );
		if ( ! in_array( $modo, $validos, true ) || self::MODO_HEREDADO === $modo ) {
			return array(
				'ok'     => true,
				'error'  => '',
				'set'    => array(),
				'delete' => array( self::META_MODO, self::META_DIA, self::META_MIN, self::META_MAX, self::META_FRECUENCIA, self::META_MESES ),
			);
		}

		if ( self::MODO_TANDA === $modo ) {
			// Saneamiento de día(s)
			$dia_raw   = $raw['dia'] ?? '';
			$dia_final = 0;
			if ( is_string( $dia_raw ) && false !== strpos( $dia_raw, ',' ) ) {
				$partes = array_filter( array_map( 'intval', explode( ',', $dia_raw ) ), static fn( $d ) => $d >= 1 && $d <= self::DIA_MAXIMO );
				if ( ! empty( $partes ) ) {
					sort( $partes );
					$dia_final = implode( ', ', array_unique( $partes ) );
				}
			} else {
				$dia_num = absint( $dia_raw );
				if ( $dia_num >= 1 && $dia_num <= self::DIA_MAXIMO ) {
					$dia_final = $dia_num;
				}
			}

			// Saneamiento de meses (para personalizada)
			$meses_final = '';
			if ( self::FRECUENCIA_PERSONALIZADA === $frecuencia ) {
				$meses_raw = $raw['meses'] ?? '';
				$meses_arr = array_filter( array_map( 'intval', is_array( $meses_raw ) ? $meses_raw : explode( ',', (string) $meses_raw ) ), static fn( $m ) => $m >= 1 && $m <= 12 );
				if ( ! empty( $meses_arr ) ) {
					sort( $meses_arr );
					$meses_final = implode( ',', array_unique( $meses_arr ) );
				}
			}

			$dia_ok = ( is_int( $dia_final ) && $dia_final >= 1 && $dia_final <= self::DIA_MAXIMO ) || ( is_string( $dia_final ) && '' !== $dia_final );
			$max_ok = $max >= 1 && $max <= 365;
			$min_ok = $min >= 1 && $min <= $max;

			if ( ! $dia_ok || ! $max_ok || ! $min_ok ) {
				return array(
					'ok'     => false,
					'error'  => ! $dia_ok
						? sprintf(
							'No se guardó el pedido por tanda: el día de cierre tiene que estar entre 1 y %d, para que exista también en febrero (o días separados por coma para quincenal).',
							self::DIA_MAXIMO
						)
						: 'No se guardó el pedido por tanda: hacen falta un mínimo y un máximo de días (1 a 365) y el mínimo no puede superar al máximo.',
					'set'    => array(),
					'delete' => array(),
				);
			}

			if ( self::FRECUENCIA_PERSONALIZADA === $frecuencia && '' === $meses_final ) {
				return array(
					'ok'     => false,
					'error'  => 'No se guardó el pedido por tanda: debe seleccionar al menos un mes para la frecuencia personalizada.',
					'set'    => array(),
					'delete' => array(),
				);
			}

			$set = array(
				self::META_DIA        => $dia_final,
				self::META_MIN        => $min,
				self::META_MAX        => $max,
				self::META_FRECUENCIA => $frecuencia,
				self::META_MODO       => $modo,
			);
			$delete = array();

			if ( '' !== $meses_final ) {
				$set[ self::META_MESES ] = $meses_final;
			} elseif ( isset( $raw['meses'] ) ) {
				$delete[] = self::META_MESES;
			}

			return array(
				'ok'     => true,
				'error'  => '',
				'set'    => $set,
				'delete' => $delete,
			);
		}

		return array(
			'ok'     => true,
			'error'  => '',
			'set'    => array( self::META_MODO => $modo ),
			'delete' => array(),
		);
	}

	/**
	 * Aplica el resultado de sanitize_row a un término. Separado a propósito:
	 * la tabla del panel hace diff antes de aplicar; el term-edit aplica directo.
	 *
	 * @param int                                                          $term_id Término.
	 * @param array{set:array<string,int|string>,delete:array<int,string>} $res     Resultado ok de sanitize_row.
	 * @return void
	 */
	public static function apply_row( int $term_id, array $res ): void {
		foreach ( $res['delete'] as $meta ) {
			delete_term_meta( $term_id, $meta );
		}
		foreach ( $res['set'] as $meta => $valor ) {
			update_term_meta( $term_id, $meta, $valor );
		}
	}

	/**
	 * La promesa que un cliente vería HOY con una política dada. Compartida
	 * por el term-edit y la columna «Lo que ve el cliente» de la tabla del
	 * panel (server-side y sobre lo GUARDADO — la vista en vivo exigiría
	 * duplicar el cálculo en JS y las dos copias divergirían).
	 *
	 * @param string $modo       Modo guardado.
	 * @param mixed  $dia        Día de cierre guardado (int o string "5, 20").
	 * @param int    $min        Días mínimos guardados.
	 * @param int    $max        Días máximos guardados.
	 * @param string $frecuencia Frecuencia guardada.
	 * @param string $meses      Meses guardados para personalizada.
	 * @return string '' si el modo no produce vista.
	 */
	public static function vista_previa( string $modo, $dia, int $min, int $max, string $frecuencia = self::FRECUENCIA_MENSUAL, string $meses = '' ): string {
		if ( self::MODO_PAUSADA === $modo ) {
			return 'La ficha dice «sin fecha confirmada»: no se promete plazo para esta editorial.';
		}

		$dias = self::dias_de_cierre( $dia, $frecuencia );
		if ( self::MODO_TANDA !== $modo || empty( $dias ) || $min < 1 || $max < $min ) {
			return '';
		}

		$tz     = StoreTime::zone();
		$policy = new self();
		$hoy    = time();
		$cierre = $policy->proximo_cierre( $hoy, $dia, $tz, $frecuencia, $meses );
		$frase  = $policy->frase_de_tanda(
			array(
				'modo'       => $modo,
				'dia'        => $dia,
				'min'        => $min,
				'max'        => $max,
				'frecuencia' => $frecuencia,
				'meses'      => $meses,
				'marca'      => '',
			),
			$hoy,
			$tz
		);
		$desde  = ( new \DateTimeImmutable( '@' . $cierre ) )->setTimezone( $tz )->modify( '+' . $min . ' days' );
		$hasta  = ( new \DateTimeImmutable( '@' . $cierre ) )->setTimezone( $tz )->modify( '+' . $max . ' days' );

		// La frase de tanda termina en punto; se concatena como segunda oración.
		return sprintf(
			'%s Llega %s – %s.',
			$frase,
			$desde->format( 'd-m-Y' ),
			$hasta->format( 'd-m-Y' )
		);
	}
}
