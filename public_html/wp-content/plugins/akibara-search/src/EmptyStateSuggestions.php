<?php
/**
 * EmptyStateSuggestions — alternativas de navegación cuando una búsqueda da cero resultados.
 *
 * Ofrece al cliente alternativas estructuradas cuando una búsqueda no encuentra tomos en
 * catálogo, orientando hacia la landing de la serie o términos populares si la consulta
 * contiene colas de formato, tomos no disponibles o sufijos descriptivos.
 *
 * Opera sobre datos existentes en memoria sin consultas adicionales pesadas:
 *
 *   - Catálogo de series en memoria (`SeriesSuggestions`). Evalúa la consulta completa
 *     y, de forma decreciente palabra a palabra, sus prefijos:
 *       1. El nombre completo de la serie encabeza la consulta (`name_heads_query()`),
 *          con su última palabra entera: «frieren 3» → Frieren, «jujutsu kaisen60» → Jujutsu Kaisen.
 *          Si el nombre consta de una sola palabra que cuenta, el resto debe ser formato (`tail_is_format()`).
 *       2. El nombre continúa más allá de lo ingresado (`name_continues_prefix()`) con al menos
 *          dos palabras de contenido y el remanente consiste en números o términos de formato
 *          («kaguya sama novela» → Kaguya Sama Love Is War).
 *     Reglas de exclusión para prevenir asociaciones erróneas:
 *       - Series de una sola palabra requieren estrictamente términos de formato válidos para asociarse.
 *       - La última palabra del nombre debe coincidir íntegra («love hinamatsuri» no ofrece Love Hina).
 *       - Colas no reconocidas como formato o números descartan la sugerencia.
 *     Términos de formato reconocidos por omisión en `default_tail_words()`, con variaciones
 *     de género y número («mangas», «nueva edicion», «novelas ligeras», «edicion española»).
 *   - Populares: principales términos de `PopularQueries` respetando el interruptor del panel.
 *
 * Sin «¿quisiste decir…?» adicional: la corrección ortográfica y relajación de marca ya
 * operan antes de declarar cero resultados (`QueryEngine::search_scored()`).
 *
 * Arquitectura tolerante a fallos: bloque puramente aditivo; si falla la lectura de sugerencias,
 * el aviso canónico de sin resultados se sirve de forma segura sin interrumpir la página.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.75
 */
final class EmptyStateSuggestions {

	/** Interruptor en Buscador → Ajustes. Sin fila guardada, encendido. */
	public const OPTION_ENABLED = 'akb_search_empty_suggestions';

	/** Series ofrecidas: las mismas dos ranuras que el desplegable. */
	public const SERIES_LIMIT = SeriesSuggestions::SUGGEST_LIMIT;

	/** Búsquedas ofrecidas. `PopularQueries` ya entrega como mucho cinco. */
	public const POPULAR_LIMIT = 5;

	/**
	 * Largo mínimo del prefijo que se prueba contra las series.
	 *
	 * Con dos o tres letras, «my dress up darling» terminaba ofreciendo «My Hero
	 * Academia»: el recorte deja un prefijo que casa con demasiadas cosas.
	 */
	public const MIN_PREFIX_LENGTH = 4;

	/**
	 * Palabras que cuentan —las que no están entre las ignoradas— con las que
	 * lo escrito ya nombra una obra. Decide DOS cosas, y cambiarla cambia las dos:
	 *
	 *   - Lo que tiene que conservar un recorte para ofrecer una serie cuyo
	 *     nombre sigue más allá de él (`name_continues_prefix()`). Con una,
	 *     «tokyo ghoul» ofrecía Tokyo Revengers y «black butler» Black Clover;
	 *     con dos, «kaguya sama», «re zero» o «code geass» ya nombran una obra.
	 *   - Las que necesita el nombre de una serie que ENCABEZA la búsqueda para
	 *     aceptar cualquier cosa detrás (`name_heads_query()`). Con menos
	 *     —Kingdom, Monster, The Climber— lo que sigue sólo puede ser cola de
	 *     formato: «kingdom hearts» es otra obra. Con dos —One
	 *     Piece, Blue Lock, Death Note, Chainsaw Man— vale cualquier cosa: «one
	 *     piece film red» ofrece One Piece. Subirla a tres para exigirle más al
	 *     recorte haría que esas series también pidieran cola de formato, y
	 *     «one piece film red» se quedaría sin serie.
	 *
	 * Las ignoradas son las del panel (Buscador → Palabras ignoradas): «the
	 * walking cat» deja «the walking», que cuenta una sola.
	 */
	public const MIN_CONTENT_WORDS = 2;

	/**
	 * Palabras de formato: las únicas, junto con los números y las palabras
	 * ignoradas, que pueden venir detrás de un prefijo que el nombre de la serie
	 * continúa. Se editan en Buscador → Ajustes; sin fila guardada, las de
	 * fábrica.
	 */
	public const OPTION_TAIL_WORDS = 'akb_search_empty_tail_words';

	/** Tope de recortes: una consulta larguísima no puede convertirse en un bucle largo. */
	private const MAX_TOKENS = 8;

	/**
	 * Candidatas que se piden al catálogo por cada prefijo probado.
	 *
	 * Más que las dos ranuras que se pintan: al recortar, `series_for()` prefiere
	 * la que encabeza la búsqueda, y ésa puede venir tercera en el orden del
	 * desplegable, que desempata por tomos publicados («saint seiya» con Lost
	 * Canvas y Next Dimension por delante de la Kanzenban). El match es en memoria
	 * sobre ~110 series: pedir doce no cuesta nada.
	 */
	private const LOOKUP_POOL = 12;

	/** @var callable(string): array<int, mixed> */
	private $series_lookup;

	/** @var callable(): array<int, mixed> */
	private $popular_top;

	/**
	 * @param callable(string): array<int, mixed> $series_lookup Series que casan con un texto (forma de `SeriesSuggestions::suggest()`).
	 * @param callable(): array<int, mixed>        $popular_top   Búsquedas más frecuentes ya normalizadas.
	 */
	public function __construct( callable $series_lookup, callable $popular_top ) {
		$this->series_lookup = $series_lookup;
		$this->popular_top   = $popular_top;
	}

	/**
	 * Cableado de producción: las mismas fuentes que usa el desplegable.
	 *
	 * @param \wpdb $db Conexión.
	 * @return self
	 */
	public static function from_runtime( $db ): self {
		$popular = new PopularQueries( $db );

		return new self(
			self::series_lookup( new SeriesSuggestions() ),
			static fn(): array => $popular->top()
		);
	}

	/**
	 * La consulta de series del cableado de producción, con su reserva de
	 * candidatas (`LOOKUP_POOL`). Pública para que la prueba ejercite ESTE
	 * cableado sobre el `SeriesSuggestions` real, y no una copia suya.
	 *
	 * @param SeriesSuggestions $series Catálogo de series.
	 * @return callable(string): array<int, mixed>
	 */
	public static function series_lookup( SeriesSuggestions $series ): callable {
		return static fn( string $text ): array => $series->suggest( $text, self::LOOKUP_POOL );
	}

	/**
	 * ¿Está encendido? Se lee igual que se guarda: sólo `yes` enciende.
	 *
	 * El formulario escribe `yes` o `no`. Un valor raro (llegado por
	 * `wp option update`) apaga el bloque y el panel lo pinta apagado, en vez de
	 * leerse encendido y pintarse apagado —que es como un «Guardar» sin tocar
	 * nada termina apagando algo de verdad.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === (string) get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Palabras de formato de fábrica: lo que el cliente escribe DETRÁS del
	 * nombre de la serie para decir qué tomo, qué edición o en qué condiciones
	 * lo quiere, en español e inglés. Ninguna nombra una obra.
	 *
	 * Van en singular y en masculino: el plural y el otro género los acepta
	 * `format_forms()` («mangas», «nueva», «novelas ligeras», «completos»,
	 * «española», «inglesa»), igual para las que agregue el dueño (revisiones
	 * RN-REV2-1 y RN-REV3-1).
	 *
	 * Van en tres grupos, y cada uno existe porque sin él una búsqueda en cero
	 * que antes ofrecía su serie se quedaba sin ella (medido en el espejo con
	 * el motor anterior al filtro de cola):
	 *   - formato, edición e idioma: «kaguya sama novela», «re zero libros»,
	 *     «slam dunk edicion de lujo», «slam dunk tapa dura», «kaguya sama en
	 *     español», «re zero en ingles», «kaguya sama mexico», «slam dunk
	 *     edicion mexicana» (revisiones RN1-D1, RN-REV2-1 y RN-REV3-1: el país de
	 *     la edición va como el cliente lo escribe, sustantivo o gentilicio);
	 *   - el tomo en palabras hasta el veinte, en ordinal hasta el décimo o en
	 *     romano hasta el xx: «kaguya sama tomo uno», «demon slayer sexta
	 *     temporada», «kaguya sama tomo xii». Más allá, el cliente escribe la
	 *     cifra, que `tail_is_format()` acepta siempre, igual que el ordinal con
	 *     cifra («3ra», «2do», «1era»);
	 *   - cómo se compra: «kaguya sama a pedido», «demon slayer preventa», «re
	 *     zero precio», «slam dunk usado», «tomos sueltos», «colección
	 *     completa», «kaguya sama encargo», «attack on titan disponibles». Es el
	 *     vocabulario de la propia tienda —la meta de las landings dice «tomos a
	 *     pedido»—, y quien lo escribe busca la serie, no otra obra (revisiones
	 *     RN-REV1-1 y RN-REV2-1).
	 * Las palabras ignoradas —«en», «de», «la», «a»— no hace falta repetirlas
	 * aquí: `tail_is_format()` ya las salta.
	 *
	 * @return array<int, string>
	 */
	public static function default_tail_words(): array {
		return array(
			'novela', 'ligera', 'light', 'novel', 'ln', 'manga', 'comic', 'libro',
			'tomo', 'vol', 'volumen', 'volume', 'n', 'nro', 'numero',
			'edicion', 'ed', 'edition', 'reedicion', 'version', 'deluxe', 'kanzenban', 'integral', 'omnibus', 'especial',
			'box', 'pack', 'set', 'coleccionista', 'coleccion', 'lujo', 'color',
			'tapa', 'dura', 'blanda', 'fisico', 'original',
			'espanol', 'castellano', 'ingles', 'english', 'japones', 'chile', 'chileno', 'argentina', 'mexico', 'mexicano', 'espana',
			'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
			'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
			'primer', 'primero', 'segundo', 'tercer', 'tercero', 'cuarto', 'quinto',
			'sexto', 'septimo', 'setimo', 'octavo', 'noveno', 'decimo', 'ultimo', 'final',
			'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x',
			'xi', 'xii', 'xiii', 'xiv', 'xv', 'xvi', 'xvii', 'xviii', 'xix', 'xx',
			'temporada', 'season', 'parte', 'part', 'completo', 'todo',
			'preventa', 'pedido', 'encargo', 'reserva', 'importado',
			'oferta', 'promocion', 'precio', 'descuento', 'barato',
			'usado', 'nuevo', 'suelto', 'stock', 'disponible',
		);
	}

	/**
	 * Palabras de formato vigentes, tal como se guardaron (para el panel).
	 *
	 * Se leen con el MISMO validador con que se guardan
	 * (`Stopwords::word_is_valid()`): lo que el panel no aceptaría tampoco se
	 * usa. Una lista guardada vacía es válida —«sólo números y palabras
	 * ignoradas»— y distinta de no haber guardado nada, igual que en Palabras
	 * ignoradas.
	 *
	 * @return array<int, string>
	 */
	public static function tail_words(): array {
		$stored = get_option( self::OPTION_TAIL_WORDS, null );
		if ( ! is_array( $stored ) ) {
			return self::default_tail_words();
		}

		$words = array();
		foreach ( $stored as $word ) {
			if ( Stopwords::word_is_valid( $word ) ) {
				$words[] = (string) $word;
			}
		}

		return array_values( array_unique( $words ) );
	}

	/**
	 * Las palabras de formato vigentes como las compara el motor.
	 *
	 * @return array<string, true>
	 */
	private static function tail_word_set(): array {
		return self::word_set( self::tail_words() );
	}

	/**
	 * Una lista de palabras como la ve el motor: normalizada igual que la
	 * búsqueda («edición» → «edicion», «Vol.» → «vol») y sin repetir.
	 *
	 * @param array<int, string> $words Lista cruda.
	 * @return array<string, true>
	 */
	private static function word_set( array $words ): array {
		$set = array();
		foreach ( $words as $word ) {
			foreach ( explode( ' ', Normalizer::normalize( (string) $word ) ) as $piece ) {
				if ( '' !== $piece ) {
					$set[ $piece ] = true;
				}
			}
		}

		return $set;
	}

	/**
	 * Huella normalizada de una lista de palabras de formato.
	 *
	 * El panel utiliza esta clave canónica para determinar si la lista configurada
	 * es equivalente a la de fábrica (independientemente del orden de los términos)
	 * y registrar cambios únicamente cuando existe una variación real de contenido.
	 *
	 * @param array<int, string> $words Lista cruda.
	 * @return string Palabras normalizadas, ordenadas y separadas por coma.
	 */
	public static function tail_words_key( array $words ): string {
		$set = array_map( 'strval', array_keys( self::word_set( $words ) ) );
		sort( $set, SORT_STRING );

		return implode( ', ', $set );
	}

	/**
	 * Sugerencias para una búsqueda que dio cero.
	 *
	 * @param string $query Lo que escribió el cliente.
	 * @return array{series: array<int, array{name: string, url: string, meta: string}>, popular: array<int, string>}
	 */
	public function for_query( string $query ): array {
		$empty = array(
			'series'  => array(),
			'popular' => array(),
		);

		try {
			if ( ! self::enabled() ) {
				return $empty;
			}
			$normalized = Normalizer::normalize( $query );
			if ( '' === $normalized ) {
				return $empty;
			}

			return array(
				'series'  => $this->series_for( $normalized ),
				'popular' => $this->popular_for( $normalized ),
			);
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return $empty;
		}
	}

	/**
	 * La serie que más se parece a lo escrito: la consulta entera y después sus
	 * prefijos, del más largo al más corto. Gana el primero que encuentre algo.
	 *
	 * En cada recorte, la serie cuyo nombre entero encabeza la búsqueda gana a
	 * la que sólo la continúa: «saint seiya edicion kanzenban 22» ofrece la
	 * Kanzenban y no Lost Canvas, aunque las dos empiecen por «saint seiya», y
	 * «one piece film red» ofrece One Piece y no One Piece 3 en 1.
	 *
	 * @param string $normalized Consulta ya normalizada.
	 * @return array<int, array{name: string, url: string, meta: string}>
	 */
	private function series_for( string $normalized ): array {
		$words  = explode( ' ', $normalized );
		$tokens = array_slice( $words, 0, self::MAX_TOKENS );
		$format = self::tail_word_set();

		for ( $keep = count( $tokens ); $keep >= 1; $keep-- ) {
			$kept   = array_slice( $tokens, 0, $keep );
			$prefix = implode( ' ', $kept );
			// Los prefijos sólo se acortan: si éste ya no llega, ninguno llega.
			if ( strlen( $prefix ) < self::MIN_PREFIX_LENGTH ) {
				break;
			}

			$heads     = array();
			$continues = array();
			// Lo recortado se mira contra TODA la consulta, no sólo lo que cupo
			// en el tope de recortes.
			$tail_is_format = self::tail_is_format( array_slice( $words, $keep ), $format );
			foreach ( (array) ( $this->series_lookup )( $prefix ) as $entry ) {
				$series = self::valid_series( $entry );
				if ( null === $series ) {
					continue;
				}
				// La consulta entera conserva el criterio del desplegable.
				// Recortada, se compara con TODA la consulta, no con la parte
				// que cupo en el tope de recortes.
				if ( count( $words ) === $keep || self::name_heads_query( $series['name'], $words, $format ) ) {
					$heads[] = $series;
				} elseif ( $tail_is_format && self::name_continues_prefix( $series['name'], $kept ) ) {
					$continues[] = $series;
				}
			}
			$found = array() !== $heads ? $heads : $continues;
			if ( array() !== $found ) {
				return array_slice( $found, 0, self::SERIES_LIMIT );
			}
		}

		return array();
	}

	/**
	 * Lo más buscado, sin la propia búsqueda que acaba de fallar.
	 *
	 * @param string $normalized Consulta ya normalizada.
	 * @return array<int, string>
	 */
	private function popular_for( string $normalized ): array {
		$out = array();
		foreach ( (array) ( $this->popular_top )() as $popular ) {
			if ( ! is_string( $popular ) ) {
				continue;
			}
			$popular = trim( $popular );
			if ( '' === $popular || $popular === $normalized || in_array( $popular, $out, true ) ) {
				continue;
			}
			$out[] = $popular;
			if ( count( $out ) >= self::POPULAR_LIMIT ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * ¿El nombre ENTERO de la serie encabeza lo que escribió el cliente, sin que
	 * lo que sigue nombre otra obra?
	 *
	 * Encabeza si cubre las primeras palabras de la búsqueda
	 * (`words_covered()`): «saint seiya edicion kanzenban 22» → Saint Seiya Ed.
	 * Kanzenban, «jujutsu kaisen60» → Jujutsu Kaisen, «dandadan 5» → Dan Da Dan.
	 * Lo que sobra al final es la cola que se recortó: tomo, formato, edición.
	 *
	 * Que la serie comience por lo que quedó tras recortar no basta por sí solo:
	 * una palabra suelta ambigua compartiría prefijos con obras completamente diferentes.
	 * Ese caso lo decide `name_continues_prefix()`, y sólo si esta regla no encontró
	 * nada en el mismo recorte.
	 *
	 * Un nombre con una sola palabra que cuenta —Kingdom, Monster, Akira, The
	 * Climber— encabeza casi cualquier cosa: «kingdom hearts», «monster high» o
	 * «akira kurosawa» son otras obras y no se distinguen de la cola de un tomo
	 * sin saber qué es cada una. Por eso, igual que cuando el nombre sigue más
	 * allá de lo escrito, lo que viene detrás sólo puede ser cola
	 * (`tail_is_format()`): «frieren 3» o «monster edicion deluxe» ofrecen su
	 * serie; «frieren preludio» y «naruto shippuden 72» no, salvo que el
	 * término esté entre las palabras de formato. Con `MIN_CONTENT_WORDS`
	 * palabras que cuentan (dos) el nombre ya nombra una obra y acepta cualquier
	 * cosa detrás: «one piece film red» → One Piece.
	 *
	 * Los errores de tecleo no se toleran aquí: los arregla el corrector
	 * (`akb_search_fuzzy`, encendido en producción) antes de dar cero —«demon
	 * smayer» y «jujutsu kiasen», en cero en el qlog, hoy ya traen tomos—.
	 *
	 * @param string              $name   Nombre visible de la serie.
	 * @param array<int, string>  $words  Palabras de la búsqueda normalizada.
	 * @param array<string, true> $format Palabras de formato normalizadas.
	 * @return bool
	 */
	private static function name_heads_query( string $name, array $words, array $format ): bool {
		$name_norm = Normalizer::normalize( $name );
		if ( '' === $name_norm ) {
			return false;
		}
		$name_words = explode( ' ', $name_norm );
		$covered    = self::words_covered( $name_words, $words );
		if ( null === $covered ) {
			return false;
		}

		return self::content_words( $name_words ) >= self::MIN_CONTENT_WORDS
			|| self::tail_is_format( array_slice( $words, $covered ), $format );
	}

	/**
	 * Cuántas palabras de la búsqueda cubre el nombre de la serie desde el
	 * principio, o null si no la encabeza.
	 *
	 * Palabra a palabra: las del medio pueden ser el comienzo de la escrita
	 * —«Ed.» abrevia «edicion»—, pero la ÚLTIMA tiene que ser la escrita entera,
	 * con el tomo pegado —«kaisen60»— o en plural —«death notes»—. Debe coincidir
	 * de forma completa para evitar falsos positivos («love hinamatsuri» no ofrece Love Hina).
	 *
	 * Vale también el nombre pegado, la misma regla que ya aplica el desplegable
	 * —«dandadan 5» → Dan Da Dan—, y con la misma exigencia: el nombre tiene que
	 * acabar donde acaba una palabra escrita, o seguirle sólo el tomo o el plural.
	 *
	 * @param array<int, string> $name_words Palabras del nombre, normalizadas.
	 * @param array<int, string> $words      Palabras de la búsqueda normalizada.
	 * @return int|null
	 */
	private static function words_covered( array $name_words, array $words ): ?int {
		$last = count( $name_words ) - 1;
		if ( $last < count( $words ) ) {
			$heads = true;
			foreach ( $name_words as $i => $name_word ) {
				if ( ! str_starts_with( $words[ $i ], $name_word )
					|| ( $i === $last && ! self::is_word_ending( substr( $words[ $i ], strlen( $name_word ) ) ) ) ) {
					$heads = false;
					break;
				}
			}
			if ( $heads ) {
				return $last + 1;
			}
		}

		$glued  = implode( '', $name_words );
		$joined = '';
		foreach ( $words as $i => $word ) {
			$joined .= $word;
			if ( strlen( $joined ) >= strlen( $glued ) ) {
				return str_starts_with( $joined, $glued ) && self::is_word_ending( substr( $joined, strlen( $glued ) ) ) ? $i + 1 : null;
			}
		}

		return null;
	}

	/**
	 * ¿Lo que sigue al nombre dentro de la misma palabra escrita es sólo su
	 * final —nada, el tomo pegado o el plural— y no el resto de otra palabra?
	 *
	 * @param string $rest Lo que queda de la palabra escrita tras el nombre.
	 * @return bool
	 */
	private static function is_word_ending( string $rest ): bool {
		return '' === $rest || ctype_digit( $rest ) || 's' === $rest || 'es' === $rest;
	}

	/**
	 * Palabras que cuentan: las que no están entre las ignoradas del panel, la
	 * misma lista con que el motor tokeniza. Los números cuentan siempre, igual
	 * que allí.
	 *
	 * @param array<int, string> $words Palabras normalizadas.
	 * @return int
	 */
	private static function content_words( array $words ): int {
		$content = 0;
		foreach ( $words as $word ) {
			if ( ctype_digit( $word ) || ! Stopwords::is_stopword( $word ) ) {
				++$content;
			}
		}

		return $content;
	}

	/**
	 * ¿El nombre de la serie SIGUE lo que quedó tras recortar, y eso basta para
	 * nombrar la obra?
	 *
	 * Es el caso de las series con subtítulo o edición en el nombre —Kaguya Sama
	 * Love Is War, Re:Zero Chapter Three, Demon Slayer: Kimetsu No Yaiba, Slam
	 * Dunk Deluxe—, que `name_heads_query()` nunca acepta porque el nombre tiene
	 * más palabras que lo que escribió el cliente: con esa regla sola, «kaguya
	 * sama novela» o «re zero novela» se quedaban sin serie aunque las dos
	 * tengan landing (revisión B4-2-R1, medido en el espejo).
	 *
	 * Dos condiciones:
	 *   - el nombre empieza por lo que quedó y lo continúa en un límite de
	 *     palabra: «king» no es el comienzo de Kingdom;
	 *   - lo que quedó conserva al menos `MIN_CONTENT_WORDS` palabras que
	 *     cuentan (`content_words()`).
	 *
	 * @param string             $name Nombre visible de la serie.
	 * @param array<int, string> $kept Palabras que quedaron tras recortar.
	 * @return bool
	 */
	private static function name_continues_prefix( string $name, array $kept ): bool {
		if ( self::content_words( $kept ) < self::MIN_CONTENT_WORDS ) {
			return false;
		}

		return str_starts_with( Normalizer::normalize( $name ) . ' ', implode( ' ', $kept ) . ' ' );
	}

	/**
	 * ¿Lo recortado es sólo cola —números, palabras de formato y palabras
	 * ignoradas— y no el nombre de otra obra?
	 *
	 * «kaguya sama novela» recorta «novela»: es formato, y Kaguya Sama Love Is
	 * War sale. «biblioteca vertigo sandman» recorta «sandman», que nombra otra
	 * obra de la misma colección: ofrecer Hellblazer ahí es justo el fallo que
	 * «Quizá buscabas» no puede cometer (revisión B4-3-R1, medido en el espejo
	 * con Chromium y WebKit: también «el chico que dibujaba constelaciones» → El
	 * chico que me gusta…, «el verano que nos conocimos» → El Verano que Hikaru
	 * Murió, «kaiju no hanayome» → Kaiju No 8, «hajime no kanojo» → Hajime no
	 * Ippo). Vale también la palabra con el número pegado («tomo12»). Una cola
	 * vacía no llega aquí: con la consulta entera manda el criterio del
	 * desplegable.
	 *
	 * Las palabras ignoradas —el panel de Palabras ignoradas, la misma lista con
	 * que `name_continues_prefix()` cuenta lo que quedó— no nombran nada y se
	 * saltan: «kaguya sama en español», «re zero la novela ligera» o «slam dunk
	 * edicion de lujo» ofrecen su serie, mientras «kaiju no de hanayome» sigue
	 * sin ofrecer Kaiju No 8, porque «hanayome» sí cuenta (revisión RN1-D1).
	 *
	 * El ordinal con cifra cuenta como número, igual que la cifra sola: «3ra»,
	 * «2do», «1er», «1era», «3ero», «4to» o «3rd» («kaguya sama 3ra
	 * temporada», «demon slayer 3era temporada»). No depende de la lista del
	 * panel porque no es una palabra que el dueño pueda querer quitar: es la
	 * cifra con su terminación (revisiones RN-REV1-1 y RN-REV2-1).
	 *
	 * Una palabra de formato vale también en plural y en el otro género
	 * (`format_forms()`).
	 *
	 * @param array<int, string>  $tail   Palabras recortadas, normalizadas.
	 * @param array<string, true> $format Palabras de formato normalizadas.
	 * @return bool
	 */
	private static function tail_is_format( array $tail, array $format ): bool {
		foreach ( $tail as $word ) {
			if ( ctype_digit( $word ) || self::is_format_word( $word, $format ) || Stopwords::is_stopword( $word ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^\d+(?:[ao]|er|era|ero|ro|ra|do|da|to|ta|vo|va|mo|ma|no|na|st|nd|rd|th)$/', $word ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^([a-z]+)(\d+)$/', $word, $m ) && self::is_format_word( $m[1], $format ) ) {
				continue;
			}
			return false;
		}

		return true;
	}

	/**
	 * ¿Es la palabra una de formato, o su plural, o su otro género?
	 *
	 * @param string              $word   Palabra normalizada.
	 * @param array<string, true> $format Palabras de formato normalizadas.
	 * @return bool
	 */
	private static function is_format_word( string $word, array $format ): bool {
		foreach ( self::format_forms( $word ) as $form ) {
			if ( isset( $format[ $form ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * La palabra tal cual y las formas con que puede estar en la lista: sin el
	 * plural y en el otro género.
	 *
	 * Así cada palabra de la lista —la de fábrica y las que agregue el dueño—
	 * cubre su plural y su otro género sin repetirla: «manga» cubre «mangas»,
	 * «ligera» cubre «ligeras», «nuevo» cubre «nueva» y «nuevas», «disponible»
	 * cubre «disponibles» y «edicion» cubre «ediciones». Antes, la lista
	 * nombraba cada forma a mano y a las que faltaban —«novelas ligeras»,
	 * «nueva edicion», «mangas»— se les quitaba la serie que el motor sí
	 * encontraba (revisión RN-REV2-1, medido en el espejo). Al revés no: un
	 * plural de la lista no cubre su singular, por eso la lista va en singular.
	 *
	 * El género: la que termina en -o o en -a cubre la otra terminación en los
	 * dos sentidos («nuevo» ↔ «nueva»), y la que termina en consonante cubre
	 * su femenino en -a: «espanol» cubre «espanola» y «espanolas», «ingles»
	 * cubre «inglesa» y «japones» «japonesa». Sin esto, «kaguya sama edicion
	 * española» o «re zero version inglesa» se quedaban sin serie aunque la
	 * lista nombraba el idioma (revisión RN-REV3-1, medido en el espejo). Ese
	 * caso va en un solo sentido: una palabra de la lista en -a no cubre la
	 * que resulta de quitársela.
	 *
	 * Ninguna forma baja de dos letras: «vs» no es el plural de «v» ni «is» el
	 * de «i».
	 *
	 * @param string $word Palabra normalizada.
	 * @return array<int, string>
	 */
	private static function format_forms( string $word ): array {
		$stems = array( $word );
		foreach ( array( 'es', 's' ) as $plural ) {
			$stem = substr( $word, 0, -strlen( $plural ) );
			if ( str_ends_with( $word, $plural ) && strlen( $stem ) >= 2 ) {
				$stems[] = $stem;
			}
		}

		$forms = array();
		foreach ( $stems as $stem ) {
			$forms[] = $stem;
			$last    = substr( $stem, -1 );
			if ( strlen( $stem ) >= 2 && ( 'a' === $last || 'o' === $last ) ) {
				$forms[] = substr( $stem, 0, -1 ) . ( 'a' === $last ? 'o' : 'a' );
			}
			if ( 1 === preg_match( '/^([a-z0-9]+[b-df-hj-np-tv-z])a$/', $stem, $masculine ) ) {
				$forms[] = $masculine[1];
			}
		}

		return $forms;
	}

	/**
	 * Una entrada de serie con lo mínimo para pintarla, o null.
	 *
	 * @param mixed $entry Entrada de `SeriesSuggestions::suggest()`.
	 * @return array{name: string, url: string, meta: string}|null
	 */
	private static function valid_series( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$name = isset( $entry['name'] ) && is_string( $entry['name'] ) ? trim( $entry['name'] ) : '';
		$url  = isset( $entry['url'] ) && is_string( $entry['url'] ) ? trim( $entry['url'] ) : '';
		if ( '' === $name || '' === $url ) {
			return null;
		}

		return array(
			'name' => $name,
			'url'  => $url,
			'meta' => isset( $entry['meta'] ) && is_string( $entry['meta'] ) ? trim( $entry['meta'] ) : '',
		);
	}
}
