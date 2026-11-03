<?php
/**
 * La búsqueda sin resultados deja de ser un callejón sin salida (UC-1 / UM-8,
 * auditoría del tema 2026-09-22).
 *
 * Medido en producción el 2026-09-23: 50 de 173 búsquedas enviadas en 30 días
 * terminaron en cero, y el aviso sólo tenía título y pista. Se fijan tres
 * contratos:
 *
 *   1. El motor ofrece la serie que más se parece a lo escrito —recortando la
 *      cola de la consulta, sin aceptar coincidencias interiores al recortar, y
 *      sin perder las series con subtítulo cuando lo que quedó las nombra con
 *      dos palabras— y lo más buscado, detrás de un interruptor que se lee como
 *      se guarda.
 *   2. El aviso imprime esas salidas y el hook `akibara_search_empty_after`
 *      DENTRO de `[data-akb-empty]`, que es el nodo que el PJAX reemplaza entero:
 *      así la venta cruzada del tema entra y sale con el aviso. Con resultados,
 *      el nodo sale vacío y el hook no corre.
 *   3. El dueño puede apagar las salidas y reescribir sus rótulos en Buscador →
 *      Ajustes, con los valores por defecto pintados tal como los lee el motor.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EmptyStateCopy;
use Akibara\Search\EmptyStateSuggestions;
use Akibara\Search\SearchAdminSection;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Stopwords;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once dirname( __DIR__ ) . '/src/Search.php';
require_once dirname( __DIR__ ) . '/src/FilterSet.php';
require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

defined( 'AKIBARA_SEARCH_DIR' ) || define( 'AKIBARA_SEARCH_DIR', dirname( __DIR__ ) . '/' );
defined( 'AKIBARA_SEARCH_URL' ) || define( 'AKIBARA_SEARCH_URL', 'https://example.test/wp-content/plugins/akibara-search/' );
defined( 'AKIBARA_SEARCH_VERSION' ) || define( 'AKIBARA_SEARCH_VERSION', '0.0.0-test' );

final class BusquedaSinResultadosTieneSalidaTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, string> Textos con que el motor consultó las series. */
	private array $consultas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options   = array();
		$this->consultas = array();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'akibara_search_facets_active' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		// El fichero registra sus hooks al incluirse: se carga tras Monkey\setUp().
		require_once dirname( __DIR__ ) . '/frontend/facets-render.php';

		// Las palabras ignoradas se memorizan por request: cada caso lee la
		// lista de SUS options, no la que dejó otra clase de la suite.
		$this->olvidar_palabras_ignoradas();
	}

	protected function tearDown(): void {
		$this->olvidar_palabras_ignoradas();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function olvidar_palabras_ignoradas(): void {
		Stopwords::flush();
		SemanticPublication::reset_authoritative_state_for_tests();
	}

	/**
	 * Motor con un catálogo de series de mentira que responde como
	 * `SeriesSuggestions::suggest()`: prefijo del nombre o de una palabra interior.
	 *
	 * @param array<int, string> $populares Top de búsquedas.
	 */
	private function motor( array $populares = array() ): EmptyStateSuggestions {
		$catalogo = array(
			array( 'name' => 'Frieren', 'url' => 'https://akibara.test/serie/frieren/', 'meta' => '15 tomos' ),
			array( 'name' => 'Mushoku Tensei', 'url' => 'https://akibara.test/serie/mushoku-tensei/', 'meta' => '20 tomos' ),
			array( 'name' => 'One Piece 3 en 1 (Planeta Cómic)', 'url' => 'https://akibara.test/serie/op3/', 'meta' => '13 tomos' ),
			array( 'name' => 'The Climber', 'url' => 'https://akibara.test/serie/the-climber/', 'meta' => '17 tomos' ),
			array( 'name' => 'Dan Da Dan', 'url' => 'https://akibara.test/serie/dan-da-dan/', 'meta' => '22 tomos' ),
			array( 'name' => 'My Hero Academia', 'url' => 'https://akibara.test/serie/mha/', 'meta' => '40 tomos' ),
			// Pares reales del espejo que comparten la PRIMERA palabra: sin ellos
			// ninguna prueba veía ofrecer otra obra al recortar (revisión B4-R1).
			array( 'name' => 'Tokyo Revengers', 'url' => 'https://akibara.test/serie/tokyo-revengers/', 'meta' => '31 tomos' ),
			array( 'name' => 'Black Clover', 'url' => 'https://akibara.test/serie/black-clover/', 'meta' => '37 tomos' ),
			array( 'name' => 'Naruto', 'url' => 'https://akibara.test/serie/naruto/', 'meta' => '72 tomos' ),
			array( 'name' => 'Naruto Jump Remix', 'url' => 'https://akibara.test/serie/naruto-jump-remix/', 'meta' => '12 tomos' ),
			array( 'name' => 'Kingdom', 'url' => 'https://akibara.test/serie/kingdom/', 'meta' => '20 tomos' ),
			array( 'name' => 'Jujutsu Kaisen', 'url' => 'https://akibara.test/serie/jujutsu-kaisen/', 'meta' => '26 tomos' ),
			array( 'name' => 'Saint Seiya Ed. Kanzenban', 'url' => 'https://akibara.test/serie/saint-seiya-ed-kanzenban/', 'meta' => '22 tomos' ),
			// Series cuyo nombre sigue más allá de lo que el cliente escribe: el
			// subtítulo o la edición van en el nombre (revisión B4-2-R1).
			array( 'name' => 'Kaguya-sama: Love is War', 'url' => 'https://akibara.test/serie/kaguya-sama-love-is-war/', 'meta' => '28 tomos' ),
			array( 'name' => 'Re:Zero Chapter Three', 'url' => 'https://akibara.test/serie/rezero-chapter-three/', 'meta' => '11 tomos' ),
			array( 'name' => 'Re:Zero Chapter Four', 'url' => 'https://akibara.test/serie/rezero-chapter-four/', 'meta' => '6 tomos' ),
			array( 'name' => 'Demon Slayer – Kimetsu No Yaiba', 'url' => 'https://akibara.test/serie/demon-slayer-kimetsu-no-yaiba/', 'meta' => '23 tomos' ),
			array( 'name' => 'Gantz Deluxe Edition', 'url' => 'https://akibara.test/serie/gantz-deluxe-edition/', 'meta' => '13 tomos' ),
			array( 'name' => 'The Walking Dead', 'url' => 'https://akibara.test/serie/the-walking-dead/', 'meta' => '4 tomos' ),
			array( 'name' => 'One Piece', 'url' => 'https://akibara.test/serie/one-piece/', 'meta' => '109 tomos' ),
			// Revisión B4-3-R1: series reales del espejo cuyo prefijo de dos
			// palabras comparte otra obra que NO está en la tienda.
			array( 'name' => 'Biblioteca Vertigo. John Constantine: Hellblazer', 'url' => 'https://akibara.test/serie/biblioteca-vertigo-john-constantine-hellblazer/', 'meta' => '12 tomos' ),
			array( 'name' => 'El chico que me gusta no es un chico', 'url' => 'https://akibara.test/serie/el-chico-que-me-gusta/', 'meta' => '5 tomos' ),
			array( 'name' => 'El Verano que Hikaru Murió', 'url' => 'https://akibara.test/serie/el-verano-que-hikaru-murio/', 'meta' => '6 tomos' ),
			array( 'name' => 'Kaiju No 8', 'url' => 'https://akibara.test/serie/kaiju-no-8/', 'meta' => '14 tomos' ),
			array( 'name' => 'Hajime no Ippo', 'url' => 'https://akibara.test/serie/hajime-no-ippo/', 'meta' => '30 tomos' ),
			// Revisión B4-3-R2: nombres de UNA palabra.
			array( 'name' => 'Monster', 'url' => 'https://akibara.test/serie/monster/', 'meta' => '9 tomos' ),
			array( 'name' => 'Akira', 'url' => 'https://akibara.test/serie/akira/', 'meta' => '6 tomos' ),
			// QA-SRCH-1: la última palabra del nombre como comienzo de otra más
			// larga («love hinamatsuri»).
			array( 'name' => 'Love Hina', 'url' => 'https://akibara.test/serie/love-hina/', 'meta' => '14 tomos' ),
			// Revisión RN1-D1: serie real del espejo con la edición en el nombre.
			array( 'name' => 'Slam Dunk Deluxe', 'url' => 'https://akibara.test/serie/slam-dunk-deluxe/', 'meta' => '20 tomos' ),
			// Revisión RN-REV1-1: serie real del espejo con una palabra ignorada dentro.
			array( 'name' => 'Attack On Titan Edición Deluxe', 'url' => 'https://akibara.test/serie/attack-on-titan-edicion-deluxe/', 'meta' => '17 tomos' ),
		);

		return new EmptyStateSuggestions(
			function ( string $texto ) use ( $catalogo ): array {
				$this->consultas[] = $texto;
				$out = array();
				foreach ( $catalogo as $serie ) {
					$nombre = \Akibara\Search\Normalizer::normalize( $serie['name'] );
					if ( str_starts_with( $nombre, $texto ) || str_contains( $nombre, ' ' . $texto )
						|| str_replace( ' ', '', $nombre ) === str_replace( ' ', '', $texto ) ) {
						$out[] = $serie;
					}
				}
				return $out;
			},
			static fn(): array => $populares
		);
	}

	/**
	 * @testdox La cola de la búsqueda se recorta hasta dar con la serie que el cliente nombró primero
	 */
	public function test_recorta_la_cola_hasta_la_serie(): void {
		$sugerencias = $this->motor()->for_query( 'Frieren: Novela' );

		self::assertSame( array( 'Frieren' ), array_column( $sugerencias['series'], 'name' ) );
		self::assertSame( 'https://akibara.test/serie/frieren/', $sugerencias['series'][0]['url'] );
		self::assertSame( array( 'frieren novela', 'frieren' ), $this->consultas, 'Primero la consulta entera, después sus prefijos.' );

		self::assertSame( array( 'Mushoku Tensei' ), array_column( $this->motor()->for_query( 'mushoku tensei novela' )['series'], 'name' ) );
	}

	/**
	 * @testdox Al recortar, una serie que sólo casa por una palabra interior no se ofrece
	 */
	public function test_al_recortar_no_acepta_coincidencias_interiores(): void {
		// «comic» casa con «One Piece 3 en 1 (Planeta Cómic)» por la última palabra.
		self::assertSame( array(), $this->motor()->for_query( 'comic batman' )['series'] );

		// Con la consulta ENTERA sí vale el criterio del desplegable: «clim» → The Climber.
		self::assertSame( array( 'The Climber' ), array_column( $this->motor()->for_query( 'climber' )['series'], 'name' ) );
		// Y el nombre pegado también cuenta al recortar: «dandadan 5» → Dan Da Dan.
		self::assertSame( array( 'Dan Da Dan' ), array_column( $this->motor()->for_query( 'dandadan 5' )['series'], 'name' ) );
	}

	/**
	 * @testdox Al recortar, el nombre entero de la serie tiene que encabezar la búsqueda: compartir la primera palabra no basta
	 */
	public function test_al_recortar_la_serie_entera_encabeza_la_busqueda(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// Otra obra que sólo comparte la primera palabra (medido en el espejo).
		self::assertSame( array(), $series( 'tokyo ghoul' ), '«tokyo ghoul» no es Tokyo Revengers.' );
		self::assertSame( array(), $series( 'black butler' ), '«black butler» no es Black Clover.' );
		self::assertSame( array( 'Naruto' ), $series( 'naruto tomo 72' ), 'Naruto sí encabeza; Naruto Jump Remix no.' );
		self::assertSame( array(), $series( 'naruto shippuden 72' ), 'Una palabra que cuenta y una cola que no es de formato: QA-SRCH-1.' );
		self::assertSame( array(), $series( 'king the land' ), '«king» no es Kingdom: la palabra de la serie es más larga que la escrita.' );
		// El coste asumido: sin la segunda palabra de la serie, tampoco se adivina.
		self::assertSame( array(), $series( 'jujutsu norma' ) );

		// Lo que tiene que seguir saliendo: la serie entera y después la cola.
		self::assertSame( array( 'Saint Seiya Ed. Kanzenban' ), $series( 'saint seiya edicion kanzenban 22' ), '«Ed.» abrevia «edicion».' );
		self::assertSame( array( 'Jujutsu Kaisen' ), $series( 'jujutsu kaisen60' ), 'El tomo pegado a la última palabra.' );
		self::assertSame( array( 'Jujutsu Kaisen' ), $series( 'jujutsu kaisen official' ) );
		self::assertSame( array( 'Black Clover' ), $series( 'black clover novela' ) );
	}

	/**
	 * @testdox Al recortar, una serie con subtítulo sale si lo que quedó la nombra con dos palabras que cuentan
	 */
	public function test_al_recortar_ofrece_la_serie_con_subtitulo(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// Revisión B4-2-R1: el nombre tiene más palabras que lo escrito, así que
		// nunca lo «encabeza»; antes de este caso las tres quedaban sin serie.
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama novela' ) );
		self::assertSame( array( 'Re:Zero Chapter Three', 'Re:Zero Chapter Four' ), $series( 're zero novela' ) );
		self::assertSame( array( 'Demon Slayer – Kimetsu No Yaiba' ), $series( 'demon slayer kimetsu novela' ) );
		// Números y formato con el número pegado también son cola.
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama tomo 3' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama vol12' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama edición deluxe' ) );

		// La serie que encabeza gana a la que sólo continúa, en el mismo recorte:
		// «one piece» casa con las dos y el catálogo trae primero la 3 en 1.
		self::assertSame( array( 'One Piece' ), $series( 'one piece film red' ) );

		// Una palabra que cuenta no basta, ni siquiera para la serie correcta.
		self::assertSame( array(), $series( 'gantz novela' ), 'El coste asumido: «gantz» y «tokyo» no se distinguen.' );
		self::assertSame( array(), $series( 'the walking novela' ), '«the» es una palabra ignorada: «the walking» cuenta una sola.' );
		self::assertSame( array(), $series( 'tokyo ghoul' ) );
		// Y el nombre tiene que seguir en un límite de palabra.
		self::assertSame( array(), $series( 'kaguya sam novela' ), '«sam» no es la palabra «sama».' );
	}

	/**
	 * @testdox Al recortar, si lo que sobra nombra OTRA obra no se ofrece la serie que comparte el prefijo (B4-3-R1)
	 */
	public function test_al_recortar_una_cola_que_nombra_otra_obra_no_ofrece_serie(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// Las reproducciones del revisor, en el espejo con Chromium y WebKit.
		self::assertSame( array(), $series( 'biblioteca vertigo sandman' ), 'Sandman no es Hellblazer.' );
		self::assertSame( array(), $series( 'biblioteca vertigo preacher' ) );
		self::assertSame( array(), $series( 'biblioteca vertigo 100 balas' ), 'Un número no rescata una cola que ya nombró otra obra.' );
		self::assertSame( array(), $series( 'el chico que dibujaba constelaciones' ) );
		self::assertSame( array(), $series( 'el verano que nos conocimos' ) );
		self::assertSame( array(), $series( 'kaiju no hanayome' ) );
		self::assertSame( array(), $series( 'hajime no kanojo' ) );
		// El coste asumido: un spin-off tampoco trae la serie madre.
		self::assertSame( array(), $series( 'demon slayer kimetsu gakuen' ) );

		// Con cola de formato, la misma colección sí.
		self::assertSame( array( 'Biblioteca Vertigo. John Constantine: Hellblazer' ), $series( 'biblioteca vertigo tomo 3' ) );
	}

	/**
	 * @testdox Al recortar, las palabras ignoradas y las coletillas en castellano de la cola no nombran otra obra (RN1-D1)
	 */
	public function test_al_recortar_las_palabras_ignoradas_de_la_cola_no_quitan_la_serie(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );
		$rezero = array( 'Re:Zero Chapter Three', 'Re:Zero Chapter Four' );

		// Las reproducciones del revisor: búsquedas en cero que ANTES del filtro
		// de cola ofrecían la serie buena y con él se quedaron sin ella.
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama en español' ) );
		self::assertSame( $rezero, $series( 're zero en español' ) );
		self::assertSame( array( 'Demon Slayer – Kimetsu No Yaiba' ), $series( 'demon slayer en español' ) );
		self::assertSame( array( 'Slam Dunk Deluxe' ), $series( 'slam dunk en español' ) );
		self::assertSame( array( 'Slam Dunk Deluxe' ), $series( 'slam dunk edicion de lujo' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama la novela' ) );
		self::assertSame( $rezero, $series( 're zero la novela ligera' ) );
		self::assertSame( array( 'Demon Slayer – Kimetsu No Yaiba' ), $series( 'demon slayer edicion de coleccionista' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama tomo uno' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama en castellano' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama a color' ) );
		self::assertSame( $rezero, $series( 're zero the novel' ), 'Las inglesas de la lista también.' );
		self::assertSame( array( 'Slam Dunk Deluxe' ), $series( 'slam dunk coleccion' ) );

		// Una palabra ignorada no rescata la cola que ya nombró otra obra.
		self::assertSame( array(), $series( 'kaiju no de hanayome' ) );
		self::assertSame( array(), $series( 'biblioteca vertigo la cosa del pantano' ) );
		self::assertSame( array(), $series( 'el chico que la dibujaba' ) );
	}

	/**
	 * @testdox Al recortar, cómo se compra, la temporada y el tomo en ordinal o en romano no nombran otra obra (RN-REV1-1)
	 */
	public function test_al_recortar_los_calificativos_de_compra_y_los_ordinales_no_quitan_la_serie(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );
		$kaguya = array( 'Kaguya-sama: Love is War' );
		$rezero = array( 'Re:Zero Chapter Three', 'Re:Zero Chapter Four' );
		$demon  = array( 'Demon Slayer – Kimetsu No Yaiba' );

		// Las reproducciones del revisor: búsquedas en cero que antes del filtro
		// de cola ofrecían la serie buena y con él se quedaron sin ella.
		self::assertSame( $kaguya, $series( 'kaguya sama a pedido' ), 'El vocabulario de la propia tienda.' );
		self::assertSame( $demon, $series( 'demon slayer preventa' ) );
		self::assertSame( $rezero, $series( 're zero precio' ) );
		self::assertSame( array( 'Attack On Titan Edición Deluxe' ), $series( 'attack on titan oferta' ) );
		self::assertSame( array( 'Slam Dunk Deluxe' ), $series( 'slam dunk usado' ) );
		self::assertSame( $demon, $series( 'demon slayer segunda temporada' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama tomo iii' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama 3ra temporada' ) );
		self::assertSame( array( 'Slam Dunk Deluxe' ), $series( 'slam dunk tomos sueltos' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama coleccion completa' ) );
		self::assertSame( $rezero, $series( 're zero 2da parte' ) );
		self::assertSame( $demon, $series( 'demon slayer 1er tomo' ) );
		self::assertSame( $rezero, $series( 're zero 3rd season' ), 'El ordinal inglés también.' );

		// Un calificativo no rescata la cola que ya nombró otra obra.
		self::assertSame( array(), $series( 'biblioteca vertigo sandman preventa' ) );
		self::assertSame( array(), $series( 'kaiju no hanayome 2da temporada' ) );
		// El ordinal es la cifra con su terminación, no cualquier cifra con letras.
		self::assertSame( array(), $series( 'kaguya sama 3dx' ) );
	}

	/**
	 * @testdox El ordinal con cifra cuenta como número y no depende del panel; los calificativos de compra sí (RN-REV1-1)
	 */
	public function test_el_ordinal_con_cifra_no_depende_del_panel(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array();
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama 3ra' ) );
		// «1era» y «3ero», la grafía más común en castellano (revisión RN-REV2-1).
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama 1era' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama 3ero' ) );
		self::assertSame( array(), $series( 'kaguya sama preventa' ), '«preventa» es de la lista: el dueño la puede quitar.' );
		self::assertSame( array(), $series( 'kaguya sama tomo iii' ), 'El romano también es de la lista.' );
	}

	/**
	 * @testdox Al recortar, el plural, el otro género, el tomo más allá del quinto y las palabras de libro e idioma no quitan la serie (RN-REV2-1)
	 */
	public function test_al_recortar_el_plural_el_genero_y_los_tomos_altos_no_quitan_la_serie(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );
		$kaguya = array( 'Kaguya-sama: Love is War' );
		$rezero = array( 'Re:Zero Chapter Three', 'Re:Zero Chapter Four' );
		$demon  = array( 'Demon Slayer – Kimetsu No Yaiba' );
		$slam   = array( 'Slam Dunk Deluxe' );

		// Las reproducciones del revisor: con el motor anterior al filtro de
		// cola ofrecían la serie buena; con el filtro quedaban en cero y sin
		// «Quizá buscabas».
		self::assertSame( $rezero, $series( 're zero novelas ligeras' ), '«ligera» está en la lista; «ligeras» también tiene que valer.' );
		self::assertSame( $slam, $series( 'slam dunk nueva edicion' ), '«nuevo» cubre «nueva».' );
		self::assertSame( $demon, $series( 'demon slayer 3era temporada' ) );
		self::assertSame( $demon, $series( 'demon slayer 1era temporada' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama sexto tomo' ) );
		self::assertSame( $demon, $series( 'demon slayer sexta temporada' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama tomo xii' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama veinte' ) );
		self::assertSame( array( 'Attack On Titan Edición Deluxe' ), $series( 'attack on titan disponibles' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama mangas' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama comics' ) );
		self::assertSame( $rezero, $series( 're zero libros' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama usada' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama completos' ) );
		self::assertSame( $rezero, $series( 're zero en ingles' ) );
		self::assertSame( $slam, $series( 'slam dunk tapa dura' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama reedicion' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama encargo' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama importado' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama baratos' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama precio chile' ) );

		// El plural y el género no rescatan una cola que nombra otra obra…
		self::assertSame( array(), $series( 'biblioteca vertigo sandman' ) );
		self::assertSame( array(), $series( 'kaiju no hanayome' ) );
		self::assertSame( array(), $series( 'demon slayer kimetsu gakuen mangas' ) );
		// …ni una errata, en plural o no…
		self::assertSame( array(), $series( 'kaguya sama novlas' ) );
		// …ni convierten una letra suelta de la lista en otra palabra: «vs» no
		// es el plural de «v».
		self::assertSame( array(), $series( 'kaguya sama vs' ) );
	}

	/**
	 * @testdox Cada palabra de fábrica vale también en plural y con la otra terminación de género (RN-REV2-1)
	 */
	public function test_cada_palabra_de_fabrica_cubre_su_plural_y_su_otro_genero(): void {
		$kaguya = array( 'Kaguya-sama: Love is War' );
		$formas = static function ( string $palabra ): array {
			$plural = static fn( string $p ): string => $p . ( 1 === preg_match( '/[aeiou]$/', $p ) ? 's' : 'es' );
			$out    = array( $palabra, $plural( $palabra ) );
			if ( 1 === preg_match( '/[ao]$/', $palabra ) ) {
				$otro  = substr( $palabra, 0, -1 ) . ( str_ends_with( $palabra, 'a' ) ? 'o' : 'a' );
				$out[] = $otro;
				$out[] = $plural( $otro );
			}
			// La que termina en consonante también tiene femenino: «espanol» →
			// «espanola», «espanolas» (revisión RN-REV3-1).
			if ( 1 === preg_match( '/[b-df-hj-np-tv-z]$/', $palabra ) ) {
				$out[] = $palabra . 'a';
				$out[] = $palabra . 'as';
			}
			return $out;
		};

		$probadas = 0;
		foreach ( EmptyStateSuggestions::default_tail_words() as $palabra ) {
			// Una letra suelta —«n», «i», «v», «x»— no tiene plural ni género:
			// «vs» o «is» no son formas suyas.
			if ( strlen( $palabra ) < 2 ) {
				continue;
			}
			foreach ( $formas( $palabra ) as $forma ) {
				self::assertSame( $kaguya, array_column( $this->motor()->for_query( 'kaguya sama ' . $forma )['series'], 'name' ), "«kaguya sama {$forma}» (de «{$palabra}»)" );
				++$probadas;
			}
		}
		self::assertGreaterThan( 300, $probadas, 'La lista de fábrica entera, no una muestra.' );
	}

	/**
	 * @testdox El idioma y el país de la edición valen en femenino y como gentilicio: «edicion española», «version inglesa», «edicion mexicana» (RN-REV3-1)
	 */
	public function test_el_idioma_y_el_pais_de_la_edicion_valen_en_femenino(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );
		$kaguya = array( 'Kaguya-sama: Love is War' );
		$rezero = array( 'Re:Zero Chapter Three', 'Re:Zero Chapter Four' );

		// Las reproducciones del revisor: con el motor anterior al filtro de cola
		// ofrecían la serie; con él quedaban en cero y sin «Quizá buscabas»,
		// mientras la pareja en masculino sí la ofrecía.
		self::assertSame( $kaguya, $series( 'kaguya sama edición española' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama edicion japonesa' ) );
		self::assertSame( $rezero, $series( 're zero version inglesa' ) );
		self::assertSame( array( 'Demon Slayer – Kimetsu No Yaiba' ), $series( 'demon slayer edicion japonesa' ) );
		self::assertSame( array( 'Slam Dunk Deluxe' ), $series( 'slam dunk edicion mexicana' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama edicion chilena' ) );
		self::assertSame( array( 'Attack On Titan Edición Deluxe' ), $series( 'attack on titan edicion inglesa' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama japonesas' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama inglesas' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama espanolas' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama edicion argentina' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama edicion mexicano' ) );

		// Vale igual para lo que agregue el dueño: la regla es del motor, no de la lista.
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array( 'frances' );
		self::assertSame( $kaguya, $series( 'kaguya sama francesa' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama francesas' ) );
		// Y en un solo sentido: una de la lista en -a no cubre la que resulta de quitársela.
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array( 'francesa' );
		self::assertSame( array(), $series( 'kaguya sama frances' ) );
	}

	/**
	 * @testdox El plural y el otro género valen también para las palabras que agrega el dueño, pero un plural no cubre su singular (RN-REV2-1)
	 */
	public function test_el_plural_y_el_genero_valen_para_las_palabras_del_panel(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );
		$kaguya = array( 'Kaguya-sama: Love is War' );

		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array( 'Manga', 'nuevo', 'edición' );
		self::assertSame( $kaguya, $series( 'kaguya sama mangas' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama nueva' ) );
		self::assertSame( $kaguya, $series( 'kaguya sama nuevas ediciones' ) );
		self::assertSame( array(), $series( 'kaguya sama libros' ), '«libro» ya no está en la lista.' );

		// Lo que dice la ayuda del panel: la lista va en singular.
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array( 'mangas' );
		self::assertSame( $kaguya, $series( 'kaguya sama mangas' ) );
		self::assertSame( array(), $series( 'kaguya sama manga' ) );
	}

	/**
	 * @testdox El coste asumido de una errata en la cola queda fijado: deja sin serie (RN-REV1-1)
	 */
	public function test_una_errata_en_la_cola_deja_sin_serie(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// Documentado en el docblock y en la ayuda de «Palabras de formato»: una
		// palabra mal escrita no se distingue del nombre de otra obra.
		self::assertSame( array(), $series( 'kaguya sama novla' ) );
		self::assertSame( array(), $series( 're zero tomi 3' ) );
		self::assertSame( array(), $series( 'el chico que mw gusta no es un chico' ), 'La del qlog (revisión B4-3-R1).' );
		// Bien escrita, sale.
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama novela' ) );
	}

	/**
	 * @testdox En la cola, las palabras ignoradas son las del panel: la misma lista con que se cuentan las que quedaron (RN1-D1)
	 */
	public function test_las_palabras_ignoradas_de_la_cola_son_las_del_panel(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// El dueño guardó una lista sin «en»: «en» ya no es relleno y la cola nombra otra cosa.
		$this->options[ Stopwords::OPTION ] = array( 'la' );
		$this->olvidar_palabras_ignoradas();
		self::assertSame( array(), $series( 'kaguya sama en español' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama la novela' ) );

		// Con la lista vacía, ninguna palabra es relleno.
		$this->options[ Stopwords::OPTION ] = array();
		$this->olvidar_palabras_ignoradas();
		self::assertSame( array(), $series( 'kaguya sama la novela' ) );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama novela' ) );
	}

	/**
	 * @testdox Las palabras de formato son las del panel: vacía la lista, sólo cuentan los números y las palabras ignoradas (B4-3-R1)
	 */
	public function test_las_palabras_de_formato_son_las_del_panel(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array( 'novela' );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama novela' ) );
		self::assertSame( array(), $series( 'kaguya sama tomo 3' ), '«tomo» ya no es formato.' );

		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array();
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama 3' ) );
		self::assertSame( array(), $series( 'kaguya sama novela' ) );

		// Lo que el panel no guardaría tampoco se usa; con tildes o mayúsculas casa igual.
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array( 'Edición', str_repeat( 'x', 65 ), array( 'tomo' ), 7 );
		self::assertSame( array( 'Edición' ), EmptyStateSuggestions::tail_words() );
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama edicion' ) );

		// Sin fila, las de fábrica.
		unset( $this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] );
		self::assertSame( EmptyStateSuggestions::default_tail_words(), EmptyStateSuggestions::tail_words() );
	}

	/**
	 * @testdox Una serie cuyo nombre tiene una sola palabra que cuenta sólo sale si lo que sigue es cola: nunca ofrece otra obra (QA-SRCH-1)
	 */
	public function test_una_serie_de_una_palabra_solo_sale_con_cola_de_formato(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// Las reproducciones de QA (espejo, curl y navegador): antes las cuatro
		// ofrecían la serie de una palabra, porque el nombre entero encabezaba y
		// la cola no se miraba (el coste B4-3-R2).
		self::assertSame( array(), $series( 'monster high' ) );
		self::assertSame( array(), $series( 'kingdom rush' ) );
		self::assertSame( array(), $series( 'akira kurosawa' ) );
		self::assertSame( array(), $series( 'kingdom hearts' ) );
		self::assertSame( array(), $series( 'monster hunter' ) );
		self::assertSame( array(), $series( 'akira toriyama' ) );
		// Una sola palabra que cuenta aunque el nombre tenga dos: «the» es ignorada.
		self::assertSame( array(), $series( 'the climber everest' ) );
		// El coste: un subtítulo que la lista no conoce también deja sin serie.
		self::assertSame( array(), $series( 'frieren preludio' ) );

		// Con cola de números, formato o palabras ignoradas, sí.
		self::assertSame( array( 'Frieren' ), $series( 'frieren 16' ) );
		self::assertSame( array( 'Frieren' ), $series( 'frieren novela' ) );
		self::assertSame( array( 'Monster' ), $series( 'monster edicion deluxe' ) );
		self::assertSame( array( 'Kingdom' ), $series( 'kingdom tomo 3' ) );
		self::assertSame( array( 'Akira' ), $series( 'akira en español' ) );
		self::assertSame( array( 'The Climber' ), $series( 'the climber 5' ) );

		// Lo decide el panel: el dueño recupera «frieren preludio» agregando la palabra.
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = array_merge( EmptyStateSuggestions::default_tail_words(), array( 'preludio' ) );
		self::assertSame( array( 'Frieren' ), $series( 'frieren preludio' ) );
		self::assertSame( array(), $series( 'monster high' ) );
	}

	/**
	 * @testdox MIN_CONTENT_WORDS decide el recorte y también qué nombre que encabeza acepta cualquier cosa detrás, y su docblock dice las dos (REV-FS1-2)
	 */
	public function test_min_content_words_decide_dos_cosas_y_lo_dice(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// El recorte: dos palabras que cuentan nombran la obra; una no.
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama novela' ) );
		self::assertSame( array(), $series( 'tokyo ghoul' ) );
		// El nombre que encabeza: con dos, cualquier cosa detrás; con una, sólo cola.
		self::assertSame( array( 'One Piece' ), $series( 'one piece film red' ) );
		self::assertSame( array(), $series( 'kingdom hearts' ) );

		// Quien suba la constante pensando en el recorte tiene que leer que
		// también mueve lo segundo: antes el docblock sólo hablaba del recorte.
		$doc = (string) ( new \ReflectionClassConstant( EmptyStateSuggestions::class, 'MIN_CONTENT_WORDS' ) )->getDocComment();
		self::assertStringContainsString( 'Decide DOS cosas, y cambiarla cambia las dos', $doc );
		self::assertStringContainsString( '`name_continues_prefix()`', $doc );
		self::assertStringContainsString( '`name_heads_query()`', $doc );
		self::assertStringContainsString( '«one piece film red» se quedaría sin serie', $doc );
	}

	/**
	 * @testdox La última palabra del nombre tiene que estar entera: la escrita, con el tomo pegado o en plural, nunca su comienzo (QA-SRCH-1)
	 */
	public function test_la_ultima_palabra_del_nombre_tiene_que_estar_entera(): void {
		$series = fn( string $q ): array => array_column( $this->motor()->for_query( $q )['series'], 'name' );

		// Antes «hina» bastaba como comienzo de «hinamatsuri», palabra a palabra
		// y también pegado («lovehinamatsuri» empieza por «lovehina»).
		self::assertSame( array(), $series( 'love hinamatsuri' ) );
		self::assertSame( array(), $series( 'love hinamatsuri 3' ) );

		self::assertSame( array( 'Love Hina' ), $series( 'love hina tomo 3' ) );
		self::assertSame( array( 'Love Hina' ), $series( 'love hinas 3' ), 'El plural.' );
		self::assertSame( array( 'Jujutsu Kaisen' ), $series( 'jujutsu kaisen60' ), 'El tomo pegado.' );
		self::assertSame( array( 'Dan Da Dan' ), $series( 'dandadan 5' ), 'El nombre pegado, que acaba donde acaba la palabra.' );
		self::assertSame( array( 'Saint Seiya Ed. Kanzenban' ), $series( 'saint seiya edicion kanzenban 22' ), 'Las del medio pueden ser su comienzo: «Ed.» abrevia «edicion».' );
	}

	/**
	 * @testdox Las palabras que no cuentan son las del panel de palabras ignoradas, no una lista propia
	 */
	public function test_las_palabras_que_no_cuentan_son_las_del_panel(): void {
		// El dueño guardó una lista sin «the»: «the walking» cuenta dos palabras.
		// (Con cola de formato: «the walking cat» nombra otra obra —Walking Cat— y
		// ésa no ofrece The Walking Dead con ninguna lista, B4-3-R1.)
		$this->options[ Stopwords::OPTION ] = array( 'el', 'la' );
		$this->olvidar_palabras_ignoradas();
		self::assertSame( array( 'The Walking Dead' ), array_column( $this->motor()->for_query( 'the walking novela' )['series'], 'name' ) );
		self::assertSame( array(), $this->motor()->for_query( 'the walking cat' )['series'] );

		// Y si agrega «sama», «kaguya sama» cuenta una sola.
		$this->options[ Stopwords::OPTION ] = array( 'the', 'sama' );
		$this->olvidar_palabras_ignoradas();
		self::assertSame( array(), $this->motor()->for_query( 'kaguya sama novela' )['series'] );
		self::assertSame( array( 'Re:Zero Chapter Three', 'Re:Zero Chapter Four' ), array_column( $this->motor()->for_query( 're zero novela' )['series'], 'name' ) );
	}

	/**
	 * @testdox La pantalla de palabras ignoradas avisa que también deciden la serie de «Quizá buscabas»
	 */
	public function test_la_pantalla_de_palabras_ignoradas_lo_dice(): void {
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'esc_textarea' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_stopwords' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'También deciden qué serie ofrece «Quizá buscabas»', $html );
		// El texto dice «dos»: si cambia la regla, cambia el texto.
		self::assertSame( 2, EmptyStateSuggestions::MIN_CONTENT_WORDS );
		self::assertStringContainsString( 'conservar dos palabras que no estén en esta lista', $html );
		// Y que en lo que sobra también cuentan como relleno (RN1-D1).
		self::assertStringContainsString( 'lo que sobra sólo puede ser números, palabras de esta lista o palabras de formato', $html );
	}

	/**
	 * @testdox El cableado de producción pide más candidatas que las dos que pinta: la serie buena puede venir tercera
	 */
	public function test_el_cableado_real_no_pierde_la_serie_tras_las_dos_primeras(): void {
		$catalogo = array();
		foreach ( array(
			// Orden del desplegable para «saint seiya»: más tomos primero.
			array( 11, 'Saint Seiya Lost Canvas', 'saint-seiya-lost-canvas', 25 ),
			array( 12, 'Saint Seiya Next Dimension', 'saint-seiya-next-dimension', 12 ),
			array( 13, 'Saint Seiya Ed. Kanzenban', 'saint-seiya-ed-kanzenban', 5 ),
			array( 14, 'Tokyo Revengers', 'tokyo-revengers', 31 ),
			array( 15, 'Kaguya-sama: Love is War', 'kaguya-sama-love-is-war', 28 ),
			array( 16, 'Re:Zero Chapter Three', 'rezero-chapter-three', 11 ),
		) as list( $id, $nombre, $slug, $tomos ) ) {
			$catalogo[] = array(
				'term_id'   => $id,
				'name'      => $nombre,
				'name_norm' => \Akibara\Search\Normalizer::normalize( $nombre ),
				'url'       => 'https://akibara.test/serie/' . $slug . '/',
				'count'     => $tomos,
			);
		}
		Functions\when( 'get_transient' )->justReturn( $catalogo );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );

		$motor  = new EmptyStateSuggestions( EmptyStateSuggestions::series_lookup( new \Akibara\Search\SeriesSuggestions() ), static fn(): array => array() );
		$series = static fn( string $q ): array => array_column( $motor->for_query( $q )['series'], 'name' );

		// Lost Canvas y Next Dimension también continúan «saint seiya» y vienen
		// antes: la Kanzenban, que encabeza lo escrito, les gana.
		self::assertSame( array( 'Saint Seiya Ed. Kanzenban' ), $series( 'saint seiya edicion kanzenban 22' ) );
		self::assertSame( array( 'Saint Seiya Next Dimension' ), $series( 'saint seiya next dimension 3' ) );
		self::assertSame( array(), $series( 'tokyo ghoul' ) );
		// Con el `SeriesSuggestions` real, que normaliza «Kaguya-sama:» y «Re:Zero».
		self::assertSame( array( 'Kaguya-sama: Love is War' ), $series( 'kaguya sama novela' ) );
		self::assertSame( array( 'Re:Zero Chapter Three' ), $series( 're zero novela' ) );
		// Sin nada que encabece, las que continúan, en el orden del desplegable.
		self::assertSame( array( 'Saint Seiya Lost Canvas', 'Saint Seiya Next Dimension' ), $series( 'saint seiya novela' ) );
		// Con la búsqueda entera, el criterio y el orden del desplegable, sin tocar.
		self::assertSame( array( 'Saint Seiya Lost Canvas', 'Saint Seiya Next Dimension' ), $series( 'saint seiya' ) );
	}

	/**
	 * @testdox Un prefijo de menos de cuatro letras no se prueba: «my dress up darling» no es My Hero Academia
	 */
	public function test_prefijo_corto_no_se_prueba(): void {
		self::assertSame( array(), $this->motor()->for_query( 'my dress up darling' )['series'] );
		foreach ( $this->consultas as $consulta ) {
			self::assertGreaterThanOrEqual( EmptyStateSuggestions::MIN_PREFIX_LENGTH, strlen( $consulta ), "Se probó «{$consulta}»." );
		}
	}

	/**
	 * @testdox Lo más buscado no repite la búsqueda que acaba de fallar ni pasa de cinco
	 */
	public function test_lo_mas_buscado_sin_la_propia_busqueda(): void {
		$motor = $this->motor( array( 'one piece', 'gundam', 'bleach', 'bleach', '', 'haikyuu', 'dandadan', 'frieren', 42 ) );

		self::assertSame(
			array( 'one piece', 'bleach', 'haikyuu', 'dandadan', 'frieren' ),
			$motor->for_query( 'Gundam' )['popular']
		);
	}

	/**
	 * @testdox Apagado —o con un valor raro guardado— no ofrece nada y no consulta nada
	 */
	public function test_apagado_no_ofrece_nada(): void {
		foreach ( array( 'no', '1', 'on' ) as $valor ) {
			$this->options[ EmptyStateSuggestions::OPTION_ENABLED ] = $valor;
			$this->consultas = array();

			self::assertSame( array( 'series' => array(), 'popular' => array() ), $this->motor( array( 'one piece' ) )->for_query( 'frieren preludio' ), "Valor «{$valor}»." );
			self::assertSame( array(), $this->consultas );
		}

		unset( $this->options[ EmptyStateSuggestions::OPTION_ENABLED ] );
		self::assertTrue( EmptyStateSuggestions::enabled(), 'Sin fila guardada, encendido.' );
	}

	/**
	 * @testdox Un fallo de lectura apaga sólo las sugerencias, nunca el aviso
	 */
	public function test_un_fallo_devuelve_vacio(): void {
		$motor = new EmptyStateSuggestions(
			static function (): array {
				throw new RuntimeException( 'catálogo caído' );
			},
			static fn(): array => array( 'one piece' )
		);

		self::assertSame( array( 'series' => array(), 'popular' => array() ), $motor->for_query( 'frieren preludio' ) );

		$sin_url = new EmptyStateSuggestions(
			static fn(): array => array( array( 'name' => 'Frieren', 'url' => '' ), 'basura' ),
			static fn(): array => array()
		);
		self::assertSame( array(), $sin_url->for_query( 'frieren' )['series'], 'Una serie sin enlace no se pinta.' );
	}

	/** Lo que imprime el aviso para una vista. */
	private function aviso( array $view ): string {
		ob_start();
		\akibara_gp_akb_print_empty_state( $view );

		return (string) ob_get_clean();
	}

	/**
	 * @testdox Con resultados el nodo sale vacío y oculto, y ni el hook ni el motor corren
	 */
	public function test_con_resultados_el_nodo_sale_vacio(): void {
		Actions\expectDone( 'akibara_search_empty_after' )->never();
		Functions\expect( 'akibara_search_empty_suggestions' )->never();

		self::assertSame(
			'<div class="akb-empty-state" data-akb-empty hidden></div>',
			$this->aviso( array( 'total' => 12, 'search' => 'one piece' ) )
		);
	}

	/**
	 * @testdox Búsqueda en cero: las salidas y lo que pinte el tema quedan DENTRO del nodo que el PJAX reemplaza
	 */
	public function test_busqueda_en_cero_todo_dentro_del_nodo(): void {
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/tienda/' );
		Functions\when( 'add_query_arg' )->alias( static fn( string $k, string $v, string $url ): string => $url . '?' . $k . '=' . $v );
		Functions\expect( 'akibara_search_empty_suggestions' )
			->once()
			->with( 'frieren preludio' )
			->andReturn(
				array(
					'series'  => array( array( 'name' => 'Frieren <b>', 'url' => 'https://akibara.test/serie/frieren/', 'meta' => '15 tomos' ) ),
					'popular' => array( 'one piece', 'call of the night' ),
				)
			);
		Actions\expectDone( 'akibara_search_empty_after' )
			->once()
			->with( 'frieren preludio', 'busqueda' )
			->whenHappen(
				static function (): void {
					echo '<section class="akb-empty__cross-sell"><ul class="products"></ul></section>';
				}
			);

		$html = $this->aviso( array( 'total' => 0, 'search' => 'frieren preludio', 'has_filters' => true ) );

		self::assertStringStartsWith( '<div class="akb-empty-state" data-akb-empty><p class="akb-empty-title">Sin resultados para «frieren preludio».</p><p class="akb-empty-hint">', $html );
		self::assertStringEndsWith( '<section class="akb-empty__cross-sell"><ul class="products"></ul></section></div>', $html, 'La venta cruzada del tema va al final y DENTRO del nodo.' );

		// La serie enlaza a su landing, con el nombre escapado y el volumen legible.
		self::assertStringContainsString( '<a class="akb-empty-suggest__item akb-empty-suggest__item--series" href="https://akibara.test/serie/frieren/">Frieren &lt;b&gt;<span class="screen-reader-text">, </span><span class="akb-empty-suggest__meta">15 tomos</span></a>', $html );
		// Lo más buscado apunta a la TIENDA entera, codificado y sin seguir.
		self::assertStringContainsString( '<a class="akb-empty-suggest__item" href="https://akibara.test/tienda/?_buscar=call%20of%20the%20night" rel="nofollow">call of the night</a>', $html );
		// Rótulos por defecto, y cada lista nombrada por su rótulo.
		self::assertStringContainsString( 'id="akb-empty-suggest-series">Quizá buscabas</p><ul class="akb-empty-suggest__list" aria-labelledby="akb-empty-suggest-series">', $html );
		self::assertStringContainsString( 'id="akb-empty-suggest-popular">Lo más buscado</p>', $html );
		// Ninguna lista de sugerencias se confunde con una rejilla de productos.
		self::assertSame( 1, substr_count( $html, 'class="products"' ) );
	}

	/**
	 * @testdox Sin nada que sugerir, el aviso es el de siempre, byte a byte
	 */
	public function test_sin_sugerencias_el_aviso_de_siempre(): void {
		Functions\when( 'akibara_search_empty_suggestions' )->justReturn( array( 'series' => array(), 'popular' => array() ) );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/tienda/' );

		self::assertSame(
			'<div class="akb-empty-state" data-akb-empty><p class="akb-empty-title">Sin resultados para «gundam».</p><p class="akb-empty-hint">Prueba con menos filtros, el nombre de la serie, o <button type="button" class="akb-link" data-akb-clear>limpia todo</button>.</p></div>',
			$this->aviso( array( 'total' => 0, 'search' => 'gundam' ) )
		);
	}

	/**
	 * @testdox Los rótulos son los que el dueño guardó, escapados
	 */
	public function test_rotulos_del_panel(): void {
		$this->options[ EmptyStateCopy::OPTION_SERIES_LABEL ]  = '¿Era <i>esta</i> serie?';
		$this->options[ EmptyStateCopy::OPTION_POPULAR_LABEL ] = 'Lo que busca todo el mundo & más';
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/tienda/' );
		Functions\when( 'add_query_arg' )->alias( static fn( string $k, string $v, string $url ): string => $url . '?' . $k . '=' . $v );
		Functions\when( 'akibara_search_empty_suggestions' )->justReturn(
			array(
				'series'  => array( array( 'name' => 'Frieren', 'url' => 'https://akibara.test/serie/frieren/', 'meta' => '' ) ),
				'popular' => array( 'bleach' ),
			)
		);

		$html = $this->aviso( array( 'total' => 0, 'search' => 'frieren preludio' ) );

		self::assertStringContainsString( '>¿Era esta serie?</p>', $html, 'Las etiquetas se retiran al leer, igual que al guardar.' );
		self::assertStringContainsString( '>Lo que busca todo el mundo &amp; más</p>', $html );
		self::assertStringNotContainsString( 'akb-empty-suggest__meta', $html, 'Sin volumen no hay span vacío.' );
	}

	/**
	 * @testdox Filtros en cero: no hay texto que sugerir, pero el tema recibe su hook con el motivo
	 */
	public function test_filtros_en_cero(): void {
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\expect( 'akibara_search_empty_suggestions' )->never();
		Actions\expectDone( 'akibara_search_empty_after' )->once()->with( '', 'filtros' );

		self::assertSame(
			'<div class="akb-empty-state" data-akb-empty><p class="akb-empty-title">Ningún producto coincide con estos filtros.</p><p class="akb-empty-hint">Prueba con menos filtros, el nombre de la serie, o <button type="button" class="akb-link" data-akb-clear>limpia todo</button>.</p></div>',
			$this->aviso( array( 'total' => 0, 'search' => '', 'has_filters' => true ) )
		);
	}

	/**
	 * @testdox Si el texto sí encuentra productos y un filtro marcado deja el listado en cero, el motivo es el filtro: sin «Quizá buscabas» ni «Sin resultados para…» (QA-SRCH-4)
	 */
	public function test_busqueda_con_resultados_vaciada_por_un_filtro(): void {
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\expect( 'akibara_search_empty_suggestions' )->never();
		Actions\expectDone( 'akibara_search_empty_after' )->once()->with( 'frieren', 'filtros' );
		$chips = array(
			array( 'facet' => 'buscar', 'facet_label' => 'Búsqueda', 'value' => 'frieren', 'label' => '«frieren»' ),
			array( 'facet' => 'categoria', 'facet_label' => 'Categoría', 'value' => 'comics', 'label' => 'Cómics' ),
		);

		// La reproducción de QA: /tienda/?_buscar=frieren&_categoria=comics
		// decía «Sin resultados para «frieren».» y ofrecía Frieren, 15 tomos.
		// Y la pista es la de los filtros: su botón (`data-akb-clear="filtros"`)
		// quita los filtros y conserva la búsqueda; el «limpia todo» de la pista
		// del catálogo se llevaba también «frieren» (F-S-1).
		self::assertSame(
			'<div class="akb-empty-state" data-akb-empty><p class="akb-empty-title">Ningún producto coincide con estos filtros.</p><p class="akb-empty-hint">Tu búsqueda sí tiene resultados: <button type="button" class="akb-link" data-akb-clear="filtros">quita los filtros</button> para verlos.</p></div>',
			$this->aviso( array( 'total' => 0, 'search' => 'frieren', 'has_filters' => true, 'chips' => $chips, 'search_total' => 15 ) )
		);
	}

	/**
	 * @testdox La pista de una búsqueda vaciada por los filtros es la que el dueño guardó, y su botón conserva la búsqueda (F-S-1)
	 */
	public function test_la_pista_de_los_filtros_es_la_del_panel(): void {
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'akibara_search_empty_suggestions' )->justReturn( array( 'series' => array(), 'popular' => array() ) );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/tienda/' );
		$this->options[ EmptyStateCopy::OPTION_FILTERS ] = '<b>Hay</b> tomos de lo que buscas & más: [sin filtros] o escríbenos.';
		$chips = array(
			array( 'facet' => 'buscar', 'facet_label' => 'Búsqueda', 'value' => 'frieren', 'label' => '«frieren»' ),
			array( 'facet' => 'genero', 'facet_label' => 'Género', 'value' => 'terror', 'label' => 'Terror' ),
		);

		self::assertStringContainsString(
			'<p class="akb-empty-hint">Hay tomos de lo que buscas &amp; más: <button type="button" class="akb-link" data-akb-clear="filtros">sin filtros</button> o escríbenos.</p>',
			$this->aviso( array( 'total' => 0, 'search' => 'frieren', 'has_filters' => true, 'chips' => $chips, 'search_total' => 15 ) ),
			'Etiquetas fuera al leer, texto escapado y el botón donde el dueño puso los corchetes.'
		);

		// Donde el texto no encuentra nada, o no hay texto, la pista sigue siendo
		// la del catálogo: ahí quitar la búsqueda es justo la salida.
		$sin_nada = $this->aviso( array( 'total' => 0, 'search' => 'gundam', 'has_filters' => true, 'chips' => $chips, 'search_total' => 0 ) );
		self::assertStringContainsString( 'data-akb-clear>limpia todo</button>', $sin_nada );
		self::assertStringNotContainsString( 'data-akb-clear="filtros"', $sin_nada );
		$sin_texto = $this->aviso( array( 'total' => 0, 'search' => '', 'has_filters' => true, 'chips' => array( $chips[1] ) ) );
		self::assertStringContainsString( 'data-akb-clear>limpia todo</button>', $sin_texto );
		self::assertStringNotContainsString( 'data-akb-clear="filtros"', $sin_texto );
	}

	/**
	 * @testdox Si el texto no encuentra nada, o sólo hay la búsqueda sin filtros marcados, el motivo sigue siendo la búsqueda (QA-SRCH-4)
	 */
	public function test_la_busqueda_sin_resultados_sigue_siendo_la_busqueda(): void {
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/tienda/' );
		Functions\when( 'akibara_search_empty_suggestions' )->justReturn( array( 'series' => array(), 'popular' => array() ) );
		Actions\expectDone( 'akibara_search_empty_after' )->twice()->with( 'gundam', 'busqueda' );
		$buscar = array( 'facet' => 'buscar', 'facet_label' => 'Búsqueda', 'value' => 'gundam', 'label' => '«gundam»' );
		$filtro = array( 'facet' => 'categoria', 'facet_label' => 'Categoría', 'value' => 'comics', 'label' => 'Cómics' );

		// El texto no encuentra nada: quitar el filtro no ayuda.
		self::assertStringContainsString( 'Sin resultados para «gundam».', $this->aviso( array( 'total' => 0, 'search' => 'gundam', 'has_filters' => true, 'chips' => array( $buscar, $filtro ), 'search_total' => 0 ) ) );
		// El texto encuentra, pero no hay filtro marcado que quitar (el contexto de un archivo no es un chip).
		self::assertStringContainsString( 'Sin resultados para «gundam».', $this->aviso( array( 'total' => 0, 'search' => 'gundam', 'has_filters' => true, 'chips' => array( $buscar ), 'search_total' => 7 ) ) );
	}

	/**
	 * @testdox Serie sin tomos: el mismo aviso de siempre, y el tema recibe su hook con el motivo
	 */
	public function test_serie_sin_tomos(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( (object) array( 'name' => 'Berserk' ) );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test' . $path );
		// Con business cargado el enlace sale de SeriesHubUrl: sin página del
		// directorio publicada, cae al mismo `/series/` que sin business.
		Functions\when( 'get_posts' )->justReturn( array() );
		if ( class_exists( '\\Akibara\\Business\\Services\\SeriesHubUrl' ) ) {
			\Akibara\Business\Services\SeriesHubUrl::reset();
		}
		Functions\expect( 'akibara_search_empty_suggestions' )->never();
		Actions\expectDone( 'akibara_search_empty_after' )->once()->with( '', 'serie' );

		self::assertSame(
			'<div class="akb-empty-state" data-akb-empty><p class="akb-empty-title">Hoy no tenemos tomos de «Berserk».</p><p class="akb-empty-hint"><a href="https://akibara.test/series/">Ver todas las series</a></p></div>',
			$this->aviso( array( 'total' => 0, 'search' => '', 'has_filters' => false ) )
		);
	}

	/** Pantalla de Ajustes. */
	private function ajustes(): string {
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias(
			static fn( $checked, $current = true, $echo = true ): string => (string) $checked === (string) $current ? " checked='checked'" : ''
		);
		Functions\when( 'esc_textarea' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		$method = new ReflectionMethod( SearchAdminSection::class, 'render_settings' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( new SearchAdminSection() );

		return (string) ob_get_clean();
	}

	/**
	 * @testdox En Ajustes las palabras de formato se ven y se guardan con el validador con que el motor las lee
	 */
	public function test_ajustes_palabras_de_formato(): void {
		$html = $this->ajustes();
		self::assertMatchesRegularExpression( '/<label for="akb-words-empty_tail_words">Palabras de formato<\/label>/', $html );
		self::assertStringContainsString( "name=\"palabras_empty_tail_words\" aria-describedby=\"akb-words-empty_tail_words-desc\">novela\nligera\n", $html, 'Sin fila, las de fábrica, una por línea.' );
		// Qué cubre cada palabra y hasta dónde llegan las de fábrica (RN-REV2-1).
		self::assertStringContainsString( 'Cada palabra vale también en plural y en el otro género', $html );
		self::assertStringContainsString( 'la que termina en consonante cubre su femenino en -a: «español» cubre «española» y «españolas»', $html, 'RN-REV3-1.' );
		self::assertStringContainsString( 'Un plural no cubre su singular: escríbelas en singular y en masculino.', $html );
		self::assertStringContainsString( 'el tomo en palabras hasta el veinte, en ordinal hasta el décimo o en romano hasta el xx', $html );
		// El interruptor dice qué pasa con las series de una palabra y cómo
		// recuperar la que se pierde (QA-SRCH-1), y que un cero causado por los
		// filtros no ofrece salidas (QA-SRCH-4).
		self::assertStringContainsString( '«kingdom hearts» no ofrece Kingdom ni «monster high» Monster', $html );
		self::assertStringContainsString( 'si una palabra así no nombra otra obra en tu tienda, agrégala a las palabras de formato', $html );
		self::assertStringContainsString( '«love hinamatsuri» no ofrece Love Hina', $html );
		self::assertStringContainsString( 'son los filtros marcados los que la dejan en cero, el aviso dice eso y ofrece quitarlos', $html );
		// Lo que sobra puede llevar también palabras ignoradas (RN1-D1).
		self::assertStringContainsString( 'si lo que sobra son números, palabras ignoradas o estas palabras', $html );
		// Qué cubren las de fábrica y el coste de la errata (RN-REV1-1).
		self::assertStringContainsString( 'cómo se compra («a pedido», «preventa», «precio», «usado»)', $html );
		self::assertStringContainsString( 'Una palabra mal escrita en lo que sobra («kaguya sama novla») también deja sin serie', $html );

		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( strip_tags( (string) $v ) ) );
		$truncado = false;
		$guardado = Stopwords::sanitize_words( "Tomo, EDICIÓN\n\n" . str_repeat( 'x', 65 ) . "\ntomo", $truncado );
		self::assertSame( array( 'tomo', 'edición' ), $guardado );
		self::assertTrue( $truncado );
		$this->options[ EmptyStateSuggestions::OPTION_TAIL_WORDS ] = $guardado;
		self::assertSame( $guardado, EmptyStateSuggestions::tail_words(), 'Lo guardado se lee tal cual: el lector no es más estricto que el guardado.' );
	}

	/**
	 * @testdox En Ajustes el interruptor sale encendido y los rótulos con su texto de fábrica
	 */
	public function test_ajustes_pinta_lo_que_lee_el_motor(): void {
		$html = $this->ajustes();

		self::assertMatchesRegularExpression( '/name="toggle_empty_suggestions"[^>]*checked/', $html, 'Sin fila guardada el motor las muestra: pintarlo apagado las apagaría en el primer guardado.' );
		self::assertStringContainsString( 'name="texto_empty_series_label" value="Quizá buscabas"', $html );
		self::assertStringContainsString( 'name="texto_empty_popular_label" value="Lo más buscado"', $html );

		$this->options[ EmptyStateSuggestions::OPTION_ENABLED ] = 'no';
		self::assertDoesNotMatchRegularExpression( '/name="toggle_empty_suggestions"[^>]*checked/', $this->ajustes() );
	}

	/**
	 * @testdox Un rótulo vacío vuelve al de fábrica al guardarlo
	 */
	public function test_rotulo_vacio_vuelve_al_de_fabrica(): void {
		self::assertSame( EmptyStateCopy::DEFAULT_SERIES_LABEL, EmptyStateCopy::sanitize( '  ', EmptyStateCopy::DEFAULT_SERIES_LABEL ) );
		self::assertSame( EmptyStateCopy::DEFAULT_POPULAR_LABEL, EmptyStateCopy::sanitize( '<b></b>', EmptyStateCopy::DEFAULT_POPULAR_LABEL ) );
	}
}
