<?php
/**
 * PublicationStatus — estados de la obra y de la edición que vende la landing.
 *
 * ── Por qué existe un dato escrito a mano en un módulo que los prohíbe ───────
 * La regla 1 de `SeriesFacts` dice que un dato que no se puede derivar no se
 * muestra, y por eso el bloque hoy calla justo en la pregunta que el lector trae
 * desde Google: «¿la serie está completa?». No es una omisión que se pueda
 * arreglar consultando mejor — es que la respuesta NO ESTÁ en el catálogo. Con
 * 17 de 23 tomos publicados, `disponible + preventa + agotado` da 17 igual que
 * si la obra hubiera terminado en 17: los dos escenarios son indistinguibles
 * desde la base de datos.
 *
 * La demanda está MEDIDA en Search Console, no supuesta: «the climber cuantos
 * tomos tiene» (57 impresiones, posición 4,8) y «cuantos tomos tiene the
 * climber» (89, posición 5,5) suman 146 impresiones en 90 días de una pregunta
 * que la página no contesta arriba — la contesta el último párrafo de la
 * descripción, a ~6.400 px de scroll, o sea en móvil (72,5% del tráfico) no la
 * contesta.
 *
 * La salida no es relajar la regla 1 sino darle dos fuentes explícitas: el dueño
 * escribe por separado el estado de la obra original y el de la edición que se
 * vende desde la UI nativa del término (`PublicationStatusFields`). Acá se
 * decide qué se puede publicar con cada una. Mientras un campo esté vacío no se
 * inventa un valor por defecto.
 *
 * ── Por qué el valor es cerrado y el texto se compone acá ───────────────────
 * Si el campo fuera texto libre, la voz de marca sería la de 104 momentos
 * distintos: «completa», «Serie completa», «Ya terminó», «COMPLETA (17 tomos)».
 * El campo guarda una CLAVE de un conjunto cerrado y el español se escribe una
 * sola vez, acá. Cambiar la redacción de las 104 landings es editar los dos
 * conjuntos de estados y sus compositores, no revisar 104 términos.
 *
 * ── El contrato, para quien venga después ───────────────────────────────────
 *   - `META_WORK_STATE` (`_akb_serie_estado`) guarda UNA de las claves de
 *     `WORK_STATES`. Es la meta histórica y se conserva como fuente canónica de
 *     la obra original.
 *     Cualquier otra cosa —una cadena vacía, un valor viejo, algo escrito
 *     directo por SQL o por WP-CLI— se trata como "sin dato" y el renglón no
 *     sale. El enum se valida al ESCRIBIR y otra vez al LEER: el formulario no
 *     es la única puerta a `wp_termmeta`.
 *   - `META_WORK_TOTAL` (`_akb_serie_tomos_obra`) guarda el total de tomos de la
 *     OBRA TERMINADA, que puede ser mayor que los que Akibara tiene en catálogo.
 *     Sólo se publica junto a `COMPLETE`: en una obra en curso el total es
 *     desconocido por definición, y publicar el que alguien tecleó sería
 *     inventar el dato con otro disfraz.
 *   - `META_EDITION_STATE` y `META_EDITION_TOTAL` describen la edición exacta
 *     que vende la landing. Su total puede publicarse mientras sigue en curso:
 *     significa el total anunciado por la editorial, no lo disponible en
 *     Akibara.
 *   - Las cuatro metas empiezan por `_akb_` porque el `uninstall.php` del plugin
 *     borra `wp_termmeta` con `meta_key LIKE '\_akb\_%'`. Un nombre fuera de ese
 *     prefijo sobreviviría a la desinstalación.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Estado de publicación de una serie: contrato del dato y composición del texto.
 *
 * @since 1.100.0
 */
final class PublicationStatus {

	/**
	 * Term meta con la clave del estado. Ver el contrato en el docblock del
	 * archivo, y el prefijo `_akb_` en su tercer punto.
	 */
	public const META_WORK_STATE = '_akb_serie_estado';

	/** Term meta con el total de tomos de la obra terminada. */
	public const META_WORK_TOTAL = '_akb_serie_tomos_obra';

	/** Term meta con el estado de la edición exacta que vende la landing. */
	public const META_EDITION_STATE = '_akb_serie_estado_edicion';

	/** Term meta con el total anunciado de tomos de esa edición. */
	public const META_EDITION_TOTAL = '_akb_serie_tomos_edicion';

	/** El ámbito descrito terminó y ya se conoce su cierre. */
	public const COMPLETE = 'completa';

	/** El ámbito descrito sigue publicándose. */
	public const ONGOING = 'en_publicacion';

	/** Publicación detenida, sin final anunciado. */
	public const PAUSED = 'en_pausa';

	/** La publicación se interrumpió y no va a terminar en ese ámbito. */
	public const CANCELLED = 'cancelada';

	/** La edición alcanzó todo el material publicado de una obra aún no finalizada. */
	public const UP_TO_DATE = 'al_dia';

	/**
	 * El conjunto cerrado de la obra: clave guardada → palabra publicada.
	 *
	 * UNA sola tabla para el `<select>` del admin y para el titular del renglón,
	 * a propósito: el dueño tiene que ver en el formulario EXACTAMENTE la palabra
	 * que va a salir en las 104 landings. Si algún día el admin necesita decir
	 * algo distinto que la página, se parten en dos tablas explícitas; lo que no
	 * se hace es retocar ésta a medias y descubrir la divergencia en producción.
	 *
	 * El orden no es alfabético: es el de frecuencia esperada en un catálogo de
	 * manga, para que las dos opciones que cubren casi todo queden arriba del
	 * desplegable.
	 *
	 * `CANCELLED` acá significa que la obra original quedó sin conclusión. La
	 * cancelación de una edición usa la misma clave dentro de `EDITION_STATES`,
	 * donde significa que esa editorial no la completará.
	 *
	 * @var array<string, string>
	 */
	private const WORK_STATES = array(
		self::COMPLETE  => 'Finalizada',
		self::ONGOING   => 'En publicación',
		self::PAUSED    => 'En pausa',
		self::CANCELLED => 'Cancelada',
	);

	/** @var array<string, string> Estado de la edición → palabra publicada. */
	private const EDITION_STATES = array(
		self::COMPLETE   => 'Completa',
		self::ONGOING    => 'En publicación',
		self::UP_TO_DATE => 'Al día',
		self::PAUSED     => 'En pausa',
		self::CANCELLED  => 'Cancelada',
	);

	/**
	 * Tope del total de tomos.
	 *
	 * No es una opinión sobre cuánto puede durar una obra —One Piece va en 107 y
	 * Golgo 13 pasa de 200—: es el punto donde un número deja de ser plausible y
	 * pasa a ser un dedo que resbaló en el teclado numérico. Un «2300» tecleado
	 * de más se publicaría en la landing como un hecho.
	 */
	private const MAX_TOTAL = 999;

	/**
	 * El conjunto cerrado, para el `<select>` del formulario del término.
	 *
	 * @return array<string, string> Clave guardada → etiqueta visible.
	 */
	public static function states(): array {
		return self::work_states();
	}

	/**
	 * Estados cerrados de la obra original.
	 *
	 * @return array<string, string> Clave guardada → etiqueta visible.
	 */
	public static function work_states(): array {
		return self::WORK_STATES;
	}

	/**
	 * Estados cerrados de la edición vendida.
	 *
	 * @return array<string, string> Clave guardada → etiqueta visible.
	 */
	public static function edition_states(): array {
		return self::EDITION_STATES;
	}

	/**
	 * Normaliza al conjunto cerrado. Cadena vacía = "sin dato".
	 *
	 * Se normaliza caja y espacios ANTES de comparar: los datos de las 104 series
	 * los va a cargar tanto el dueño desde el formulario como un script por
	 * WP-CLI, y un «Completa» con mayúscula o un espacio de más no es un valor
	 * NUEVO — es el mismo escrito distinto. Rechazarlo dejaría el renglón mudo en
	 * una serie que sí tiene el dato, que es el peor de los dos errores: el
	 * conjunto sigue cerrado, lo que se acepta es la ortografía.
	 *
	 * @param mixed $raw Valor del formulario o de `wp_termmeta`.
	 * @return string Clave válida de `WORK_STATES`, o '' si no lo es.
	 */
	public static function sanitize_state( $raw ): string {
		return self::sanitize_work_state( $raw );
	}

	/**
	 * Normaliza un estado de la obra original.
	 *
	 * @param mixed $raw Valor del formulario o de `wp_termmeta`.
	 * @return string Clave válida, o cadena vacía.
	 */
	public static function sanitize_work_state( $raw ): string {
		return self::sanitize_state_for( $raw, self::WORK_STATES );
	}

	/**
	 * Normaliza un estado de la edición vendida.
	 *
	 * @param mixed $raw Valor del formulario o de `wp_termmeta`.
	 * @return string Clave válida, o cadena vacía.
	 */
	public static function sanitize_edition_state( $raw ): string {
		return self::sanitize_state_for( $raw, self::EDITION_STATES );
	}

	/**
	 * Normaliza una clave contra el conjunto cerrado recibido.
	 *
	 * @param mixed                 $raw    Valor recibido.
	 * @param array<string, string> $states Conjunto permitido.
	 * @return string Clave válida, o cadena vacía.
	 */
	private static function sanitize_state_for( $raw, array $states ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		// `strtolower` y no `mb_strtolower`: las claves son ASCII por diseño
		// justamente para que esta normalización no dependa de mbstring.
		$value = strtolower( trim( $raw ) );

		return array_key_exists( $value, $states ) ? $value : '';
	}

	/**
	 * Normaliza el total de tomos. 0 = "sin dato".
	 *
	 * Se exige un entero decimal completo con una expresión regular en vez de
	 * `is_numeric()` + cast: `is_numeric('23.5')` es true y el cast lo convierte
	 * en 23 en silencio. Un total con decimales no es un total redondeado, es un
	 * dato que quien lo escribió no tenía; publicar el entero más cercano sería
	 * inventar precisión.
	 *
	 * @param mixed $raw Valor del formulario o de `wp_termmeta`.
	 * @return int Entero entre 1 y `MAX_TOTAL`, o 0 si no lo es.
	 */
	public static function sanitize_total( $raw ): int {
		if ( ! is_scalar( $raw ) ) {
			return 0;
		}

		$value = trim( (string) $raw );
		if ( 1 !== preg_match( '/^\d+$/', $value ) ) {
			return 0;
		}

		$total = (int) $value;

		return ( $total >= 1 && $total <= self::MAX_TOTAL ) ? $total : 0;
	}

	/**
	 * Lo que hay guardado para un término, ya normalizado.
	 *
	 * ── Por qué NO viaja en el transient de `SeriesFacts` ───────────────────
	 * `SeriesFacts::facts()` cachea 6 h el resultado de UNA consulta agregada
	 * sobre los PRODUCTOS de la serie, y su método público se llama
	 * `catalog_facts()` porque eso es lo que promete: hechos derivados del
	 * catálogo. Este dato es lo contrario por definición —es el que no se deriva—
	 * y meterlo ahí rompería el contrato que sostiene todo el módulo.
	 *
	 * El motivo práctico pesa igual: los 104 valores los va a cargar el dueño
	 * desde el formulario Y un script por WP-CLI, y `update_term_meta()` NO
	 * dispara `edited_pa_serie`. Un dato cacheado 6 h se cargaría en masa y no
	 * aparecería en ninguna landing hasta seis horas después, que es exactamente
	 * la forma de parecer roto sin estarlo. Leerlo fresco cuesta como mucho UNA
	 * consulta por PRIMARY KEY —y cero cuando el object cache está caliente—
	 * contra el agregado con cinco JOIN que la página ya paga.
	 *
	 * Se sanea también al LEER: el formulario no es la única puerta a
	 * `wp_termmeta`. Un valor escrito por SQL o por un WP-CLI con un typo llega
	 * hasta acá igual, y el renglón tiene que callarse, no publicarlo.
	 *
	 * @param int $term_id Término de la serie.
	 * @return array{estado:string, tomos_obra:int}
	 */
	public static function for_term( int $term_id ): array {
		$scoped = self::scoped_for_term( $term_id );

		return array(
			'estado'     => $scoped['obra']['estado'],
			'tomos_obra' => $scoped['obra']['tomos'],
		);
	}

	/**
	 * Lee por separado la obra original y la edición vendida.
	 *
	 * @param int $term_id Término de la serie.
	 * @return array{obra:array{estado:string,tomos:int},edicion:array{estado:string,tomos:int}}
	 */
	public static function scoped_for_term( int $term_id ): array {
		if ( $term_id < 1 || ! function_exists( 'get_term_meta' ) ) {
			return array(
				'obra'    => array(
					'estado' => '',
					'tomos'  => 0,
				),
				'edicion' => array(
					'estado' => '',
					'tomos'  => 0,
				),
			);
		}

		return array(
			'obra'    => array(
				'estado' => self::sanitize_work_state( get_term_meta( $term_id, self::META_WORK_STATE, true ) ),
				'tomos'  => self::sanitize_total( get_term_meta( $term_id, self::META_WORK_TOTAL, true ) ),
			),
			'edicion' => array(
				'estado' => self::sanitize_edition_state( get_term_meta( $term_id, self::META_EDITION_STATE, true ) ),
				'tomos'  => self::sanitize_total( get_term_meta( $term_id, self::META_EDITION_TOTAL, true ) ),
			),
		);
	}

	/**
	 * Compatibilidad: compone la frase de la obra original.
	 *
	 * ── Por qué devuelve DOS piezas y no una frase ──────────────────────────
	 * «Finalizada en 23 tomos» en la tipografía del valor (Bebas Neue, 1,15 rem)
	 * mide ~155 px dentro de una columna de ~156 px en un iPhone de 375 —y menos
	 * en uno de 360—, así que en línea parte por donde caiga: «Finalizada en 23» /
	 * «tomos». Partirlo acá deja el detalle en su propio renglón y el `<dd>`
	 * crece UNA línea previsible. Es el mismo argumento, escrito en la misma
	 * hoja, por el que los números de los tomos agotados se separaron en
	 * `.akb-series-facts__vols`.
	 *
	 * ── Las tres formas de callar el número ─────────────────────────────────
	 *   1. El estado no es `COMPLETE`: en una obra en curso el total no existe.
	 *   2. No hay total válido: se publica «Finalizada» a secas, que sigue siendo
	 *      la respuesta a la pregunta que trae el lector.
	 *
	 * El conteo del catálogo NO valida este total: ahora son ejes explícitamente
	 * distintos. Una obra de 10 tomos puede venderse como 5 integrales, y una
	 * landing puede incluir packs o divisiones editoriales que hagan la cantidad
	 * de productos mayor que la numeración original. Compararlos ocultaría un dato
	 * verdadero precisamente en los casos donde separar obra y edición importa.
	 *
	 * @param string $state           Clave del estado, ya leída de la meta.
	 * @param int    $total           Total de tomos de la obra, o 0.
	 * @param int    $catalog_volumes Conteo legacy del catálogo; no es comparable y se ignora.
	 * @return array{titular:string, detalle:string}|array{} Texto SIN escapar.
	 */
	public static function phrase( string $state, int $total, int $catalog_volumes ): array {
		return self::work_phrase( $state, $total, $catalog_volumes );
	}

	/**
	 * Frase de la obra original.
	 *
	 * @param string $state           Estado de la obra.
	 * @param int    $total           Total de tomos de la obra terminada.
	 * @param int    $catalog_volumes Conteo legacy del catálogo; no es comparable y se ignora.
	 * @return array{titular:string, detalle:string}|array{}
	 */
	public static function work_phrase( string $state, int $total, int $catalog_volumes ): array {
		$state = self::sanitize_work_state( $state );
		if ( '' === $state ) {
			return array();
		}

		$headline = array(
			'titular' => self::WORK_STATES[ $state ],
			'detalle' => '',
		);

		if ( self::COMPLETE !== $state ) {
			return $headline;
		}

		$total = self::sanitize_total( $total );

		// Se conserva el tercer argumento por compatibilidad con la API 1.100.0,
		// pero ya no se compara con la obra: mide productos de la edición vendida.
		unset( $catalog_volumes );
		if ( $total < 1 ) {
			return $headline;
		}

		$headline['detalle'] = self::complete_total_detail( $total );

		return $headline;
	}

	/**
	 * Frase de la edición que se vende.
	 *
	 * @param string $state Estado de la edición.
	 * @param int    $total Total final anunciado para la edición.
	 * @return array{titular:string, detalle:string}|array{}
	 */
	public static function edition_phrase( string $state, int $total ): array {
		$state = self::sanitize_edition_state( $state );
		if ( '' === $state ) {
			return array();
		}

		$phrase = array(
			'titular' => self::EDITION_STATES[ $state ],
			'detalle' => '',
		);

		$total = self::sanitize_total( $total );
		if ( $total < 1 ) {
			return $phrase;
		}

		if ( self::COMPLETE === $state ) {
			$phrase['detalle'] = self::complete_total_detail( $total );
		} elseif ( self::ONGOING === $state ) {
			$phrase['detalle'] = sprintf(
				'%s %s %s',
				number_format_i18n( $total ),
				1 === $total ? 'tomo' : 'tomos',
				1 === $total ? 'previsto' : 'previstos'
			);
		}

		return $phrase;
	}

	/**
	 * Detalle del total final, con singular correcto.
	 *
	 * @param int $total Total ya saneado.
	 * @return string
	 */
	private static function complete_total_detail( int $total ): string {
		return sprintf(
			'en %s %s',
			number_format_i18n( $total ),
			1 === $total ? 'tomo' : 'tomos'
		);
	}
}
