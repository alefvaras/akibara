<?php
/**
 * DigestRunner — el worker: escanea un tomo publicado y envía el digest.
 *
 * Los handlers se registran SIEMPRE (un job encolado nunca puede quedar
 * colgado); el ENVÍO va detrás del flag. El re-arranque cuelga del evento REAL
 * —guardar el ajuste— y no de una acción rebotando cada quince minutos para
 * siempre: ese precio ya se pagó una vez en `BackInStock`.
 *
 * El orden ARMAR → RECLAMAR es la decisión que sostiene el módulo
 * ------------------------------------------------------------
 * El contenido del correo se construye ANTES del claim, dentro del `try`. Si
 * algo revienta armándolo, las filas siguen `pending` y Action Scheduler
 * reintenta. Al revés —reclamar primero— una excepción dejaría las filas `sent`
 * sin correo, y `uniq_event` prohíbe reencolarlas: el aviso se perdería para
 * siempre.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Utilities\Log;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class DigestRunner {

	/** Clientes por página de la consulta inversa. */
	private const FOLLOWERS_PER_PAGE = 200;

	/** Segundos hasta la página siguiente de una serie con muchos compradores. */
	private const SCAN_GAP_SECONDS = 30;

	/** Clientes por corrida del digest. */
	private const DIGEST_BATCH = 50;

	/** Segundos hasta la corrida siguiente cuando quedan pendientes. */
	private const DIGEST_GAP_SECONDS = 60;

	/** Antigüedad a la que un aviso deja de ser noticia. */
	private const EXPIRE_DAYS = 60;

	/** Segundos hasta el reintento cuando `WC_Email` todavía no es cargable. */
	private const MAILER_RETRY_SECONDS = 60;

	/** Techo de esos reintentos: cuatro minutos de gracia y se suelta. */
	private const MAX_MAILER_RETRIES = 4;

	/**
	 * Intervalo de reintento mientras el WC_Email permanece deshabilitado.
	 *
	 * Comparte el valor predeterminado con NotificationDispatcher para mantener
	 * la misma pausa operacional entre ambos caminos de correo.
	 */
	private const EMAIL_DISABLED_RETRY_SECONDS = 900;

	/** Techo de esos reintentos: una hora de gracia y se suelta. */
	private const MAX_EMAIL_DISABLED_RETRIES = 4;

	/**
	 * Republicar un tomo viejo no es una novedad. Sin este techo, los clientes
	 * que compraron la serie DESPUÉS del aviso original no tienen fila `sent`
	 * que los proteja y recibirían un «salió el tomo 12» de hace dos años.
	 */
	private const NOVELTY_DAYS = 60;

	/** @var DigestQueue */
	private DigestQueue $queue;

	/** @var FollowerFinder */
	private FollowerFinder $finder;

	/** @var CustomerCollection */
	private CustomerCollection $collection;

	/** @var CutoffPhrase */
	private CutoffPhrase $cutoff;

	/**
	 * Factory del email (inyectable en pruebas). En runtime resuelve la
	 * instancia registrada en el mailer de WC — instanciarlo aparte duplicaría
	 * los settings del form.
	 *
	 * @var callable|null
	 */
	private $email_factory;

	/**
	 * Sonda de «¿es resoluble ya un WC_Email?» (inyectable en pruebas, igual
	 * que la factory de arriba). En runtime es
	 * `Services\MailerBoot::ready()`; una prueba no puede DES-cargar
	 * una clase, así que sin esta costura la rama de mailer caído sería
	 * inalcanzable — que es justo la que perdía digests en producción.
	 *
	 * @var callable|null
	 */
	private $mailer_ready;

	/**
	 * @param DigestQueue        $queue         Cola e idempotencia.
	 * @param FollowerFinder     $finder        Consulta inversa.
	 * @param CustomerCollection $collection    Autoridad de «lo tenés».
	 * @param CutoffPhrase       $cutoff        Línea del corte.
	 * @param callable|null      $email_factory Factory del email (pruebas).
	 * @param callable|null      $mailer_ready  Sonda de mailer cargado (pruebas).
	 */
	public function __construct(
		DigestQueue $queue,
		FollowerFinder $finder,
		CustomerCollection $collection,
		CutoffPhrase $cutoff,
		?callable $email_factory = null,
		?callable $mailer_ready = null
	) {
		$this->queue         = $queue;
		$this->finder        = $finder;
		$this->collection    = $collection;
		$this->cutoff        = $cutoff;
		$this->email_factory = $email_factory;
		$this->mailer_ready  = $mailer_ready;
	}

	/**
	 * @return bool
	 */
	private function mailer_ready(): bool {
		return null !== $this->mailer_ready
			? (bool) call_user_func( $this->mailer_ready )
			: \Akibara\Business\Services\MailerBoot::ready();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( SeriesFollow::HOOK_SCAN, array( $this, 'scan' ), 10, 2 );
		add_action( SeriesFollow::HOOK_DIGEST, array( $this, 'run' ), 10, 2 );
		add_action( 'update_option_' . SeriesFollow::OPTION_ENABLED, array( $this, 'on_setting_saved' ), 10, 2 );
	}

	/**
	 * Retoma la cola cuando el dueño enciende los avisos. Es el evento que de
	 * verdad cambia la respuesta.
	 *
	 * @param mixed $old_value Valor anterior.
	 * @param mixed $value     Valor nuevo.
	 * @return void
	 */
	public function on_setting_saved( $old_value, $value ): void {
		unset( $old_value );

		if ( 'yes' !== $value || ! SeriesFollow::enabled() ) {
			return;
		}

		if ( ! $this->queue->has_pending() ) {
			return;
		}

		$this->schedule_digest( 0 );
	}

	/**
	 * Escanea un tomo publicado y encola los avisos que correspondan.
	 *
	 * @param int|array<string, mixed> $product_id Producto. Action Scheduler entrega
	 *                                             los args posicionales; el unwrap del
	 *                                             array cubre invocaciones manuales.
	 * @param int|mixed                $cursor     Último `user_id` de la página previa.
	 * @return void
	 */
	public function scan( $product_id, $cursor = 0 ): void {
		if ( is_array( $product_id ) ) {
			$cursor     = $product_id['cursor'] ?? $cursor;
			$product_id = $product_id['product_id'] ?? 0;
		}

		$product_id = (int) $product_id;
		$cursor     = max( 0, (int) $cursor );
		if ( $product_id < 1 ) {
			return;
		}

		if ( ! $this->product_is_announceable( $product_id ) ) {
			return;
		}

		$series = SeriesResolver::resolve( $product_id );
		if ( null === $series ) {
			Log::warn(
				'series-follow',
				sprintf(
					'Producto %d descartado: %s.',
					$product_id,
					SeriesResolver::discard_reason_text( SeriesResolver::discard_reason( $product_id ) )
				)
			);
			return;
		}

		$followers = $this->finder->followers_of_series( $series['term_taxonomy_id'], $cursor, self::FOLLOWERS_PER_PAGE );
		if ( null === $followers ) {
			// Lectura no fiable: salir SIN marcar nada. Action Scheduler
			// reintenta. Convertir una caída en «nadie sigue esta serie» dejaría
			// el tomo avisado sin haber avisado a nadie.
			return;
		}

		if ( array() !== $followers ) {
			$this->queue_for_followers( $product_id, $series, $followers );
		}

		$this->maybe_paginate( $product_id, $followers );
		$this->schedule_digest( SeriesFollow::digest_delay_seconds() );
	}

	/**
	 * Corrida del digest: un correo por cliente, con todas sus series dentro.
	 *
	 * @param int|mixed $mailer_try Reintentos ya gastados esperando a que
	 *                              `WC_Email` sea cargable. Viaja en los args de
	 *                              Action Scheduler —igual que el `pause_try`
	 *                              del BIS— para no inventar persistencia y para
	 *                              que la cadena muera con el techo.
	 * @param int|mixed $email_try  Reintentos ya gastados por el correo deshabilitado
	 *                              en WC. Mismo mecanismo que
	 *                              `$mailer_try`: viaja en los args de Action Scheduler,
	 *                              así que muere con la cadena y no necesita persistencia.
	 * @return void
	 */
	public function run( $mailer_try = 0, $email_try = 0 ): void {
		if ( is_array( $mailer_try ) ) {
			$email_try  = $mailer_try['email_try'] ?? $email_try;
			$mailer_try = $mailer_try['mailer_try'] ?? 0;
		}
		$mailer_try = max( 0, (int) $mailer_try );
		$email_try  = max( 0, (int) $email_try );

		if ( ! SeriesFollow::enabled() ) {
			return; // Corte seguro: nada se marca. `update_option` retoma.
		}

		// El mailer ANTES de resolver el correo. Sin esto la corrida moría con
		// `Class "WC_Email" not found` (el autoloader de WooCommerce no sabe
		// resolver esa clase; ver `Services\MailerBoot::ready()`), y
		// Action Scheduler NO reintenta un fatal: el digest se perdía con las
		// filas ya reclamadas o sin reclamar, según dónde cayera.
		if ( ! $this->mailer_ready() ) {
			if ( 0 === $mailer_try ) {
				Log::warn( 'series-follow', 'Digest diferido: WC_Email todavía no cargable.' );
			}
			$this->reschedule_unready_mailer( $mailer_try );
			return;
		}

		// Comprobar habilitación antes de reclamar filas, para no marcarlas como
		// enviadas sin entregar. Una pausa debe dejar registro y agendar su próxima
		// mirada con el mismo techo de pausa de NotificationDispatcher.

		$email = $this->email();
		if ( null === $email || ! $email->is_enabled() ) {
			if ( 0 === $email_try ) {
				Log::warn( 'series-follow', 'Digest omitido: el correo de novedades está deshabilitado en WooCommerce.' );
			}
			$this->reschedule_disabled_email( $email_try );
			return;
		}

		$this->queue->expire_older_than( self::EXPIRE_DAYS );

		$user_ids = $this->queue->pending_user_ids( self::DIGEST_BATCH );
		if ( array() === $user_ids ) {
			return;
		}

		FollowRegistry::prime( $user_ids );

		$cap_seconds = SeriesFollow::weekly_cap_days() * DAY_IN_SECONDS;
		$now         = time();
		$envie_algo  = false;
		$primer_hueco = null;

		foreach ( $user_ids as $user_id ) {
			$ultimo = FollowRegistry::last_sent_at( $user_id );
			if ( $now - $ultimo < $cap_seconds ) {
				// Tope: las filas quedan PENDING. Salen la semana que viene.
				// Se anota CUÁNDO podrá recibir el primero: sin eso, la corrida
				// siguiente se agenda a 60 s contra deuda que no puede salir.
				$libre        = $ultimo + $cap_seconds;
				$primer_hueco = null === $primer_hueco ? $libre : min( $primer_hueco, $libre );
				continue;
			}

			$this->send_to_user( $email, $user_id, $now );
			$envie_algo = true;
		}

		if ( $this->queue->has_pending() ) {
			$this->schedule_digest( $this->espera_hasta_la_proxima( $user_ids, $envie_algo, $primer_hueco, $now ) );
		}
	}

	/**
	 * Calcula cuándo volver a mirar una cola cuyo límite temporal aún no madura.
	 *
	 * Sólo alarga la espera si no se envió ningún aviso y el lote cubrió todos los
	 * clientes con deuda. Con DIGEST_BATCH lleno puede quedar otro cliente elegible
	 * y se conserva una continuación breve. La espera nunca supera un día para
	 * revisar cambios de configuración aunque el límite semanal sea más lejano.
	 *
	 * @param array<int, int> $user_ids     Clientes mirados en esta vuelta.
	 * @param bool            $envie_algo   Si salió al menos un aviso.
	 * @param int|null        $primer_hueco Instante en que el primer bloqueado se libera.
	 * @param int             $now          Reloj de la corrida.
	 * @return int Segundos hasta la próxima.
	 */
	private function espera_hasta_la_proxima( array $user_ids, bool $envie_algo, ?int $primer_hueco, int $now ): int {
		if ( $envie_algo || null === $primer_hueco || count( $user_ids ) >= self::DIGEST_BATCH ) {
			return self::DIGEST_GAP_SECONDS;
		}

		return max( self::DIGEST_GAP_SECONDS, min( DAY_IN_SECONDS, $primer_hueco - $now ) );
	}

	/**
	 * Un cliente: re-validaciones, armado, claim y envío.
	 *
	 * @param SeriesFollowEmail $email   Instancia del mailer de WC.
	 * @param int               $user_id Cliente.
	 * @param int               $now     Reloj de la corrida.
	 * @return void
	 */
	private function send_to_user( SeriesFollowEmail $email, int $user_id, int $now ): void {
		$rows = $this->queue->pending_for_user( $user_id );
		if ( array() === $rows ) {
			return;
		}

		$rows = $this->without_opted_out( $user_id, $rows );
		$rows = $this->without_owned( $user_id, $rows );
		$rows = $this->without_unpublished( $rows );
		if ( array() === $rows ) {
			return;
		}

		try {
			$plan = $this->plan_groups( $rows, $user_id );
		} catch ( \Throwable $error ) {
			Log::warn(
				'series-follow',
				sprintf( 'No se pudo armar el aviso del cliente %d: %s', $user_id, $error->getMessage() ),
				array( 'exception' => get_class( $error ) )
			);
			return; // Las filas siguen PENDING; Action Scheduler reintenta.
		}

		if ( array() === $plan['groups'] ) {
			return;
		}

		// CLAIM atómico, DESPUÉS de armar. 0 filas = otro worker se adelantó.
		if ( $this->queue->claim( $user_id, $plan['ids'] ) < 1 ) {
			return;
		}

		$sent = false;
		try {
			$sent = $email->trigger( $user_id, $plan['groups'] );
		} catch ( \Throwable $error ) {
			Log::warn(
				'series-follow',
				sprintf( 'Excepción enviando el aviso del cliente %d: %s', $user_id, $error->getMessage() ),
				array( 'exception' => get_class( $error ) )
			);
		}

		if ( ! $sent ) {
			$this->queue->release( $plan['ids'] );
			return;
		}

		// Los tomos que el correo NO nombró dejan de figurar como enviados. Va
		// aquí y no antes del envío: si el correo hubiera fallado, `release()`
		// las habría devuelto todas a pending y el siguiente intento volvería a
		// decidir cuál se anuncia.
		$this->queue->coalesce( $plan['coalesced'] );

		// El tope se sella SOLO tras el éxito: si el correo no salió, el cliente
		// no gastó su semana.
		FollowRegistry::stamp_sent( $user_id, $now );
	}

	/**
	 * Opt-out releído AHORA: cubre a quien se dio de baja entre el encolado y el
	 * envío.
	 *
	 * @param int                              $user_id Cliente.
	 * @param array<int, array<string, mixed>> $rows    Filas pendientes.
	 * @return array<int, array<string, mixed>>
	 */
	private function without_opted_out( int $user_id, array $rows ): array {
		$drop = array();
		$keep = array();

		foreach ( $rows as $row ) {
			if ( FollowRegistry::is_opted_out( $user_id, (int) $row['term_id'] ) ) {
				$drop[] = (int) $row['id'];
				continue;
			}
			$keep[] = $row;
		}

		$this->queue->drop( $drop, DigestQueue::DROP_OPTOUT );

		return $keep;
	}

	/**
	 * Propiedad revalidada AHORA con UNA sola llamada cacheada: quien compró el
	 * tomo entre el encolado y el envío no recibe el aviso.
	 *
	 * `owned_among()` devuelve `array()` tanto para «no tiene ninguno» como para
	 * «la lectura no fue fiable». Esa ambigüedad se acepta A PROPÓSITO en esta
	 * dirección: ante una caída se envía un aviso redundante a quien acaba de
	 * comprar el tomo, en vez de descartar la fila —que sería terminal— y
	 * dejarlo sin saber que salió.
	 *
	 * @param int                              $user_id Cliente.
	 * @param array<int, array<string, mixed>> $rows    Filas pendientes.
	 * @return array<int, array<string, mixed>>
	 */
	private function without_owned( int $user_id, array $rows ): array {
		$product_ids = array_map( static fn ( array $row ): int => (int) $row['product_id'], $rows );
		$owned       = array_fill_keys( $this->collection->owned_among( $user_id, $product_ids ), true );
		if ( array() === $owned ) {
			return $rows;
		}

		$drop = array();
		$keep = array();

		foreach ( $rows as $row ) {
			if ( isset( $owned[ (int) $row['product_id'] ] ) ) {
				$drop[] = (int) $row['id'];
				continue;
			}
			$keep[] = $row;
		}

		$this->queue->drop( $drop, DigestQueue::DROP_OWNED );

		return $keep;
	}

	/**
	 * Un tomo despublicado o agotado entre el encolado y el envío BORRA su fila:
	 * el motivo es transitorio y marcarla cerraría la puerta para siempre cuando
	 * vuelva a habilitarse.
	 *
	 * @param array<int, array<string, mixed>> $rows Filas pendientes.
	 * @return array<int, array<string, mixed>>
	 */
	private function without_unpublished( array $rows ): array {
		$drop = array();
		$keep = array();

		foreach ( $rows as $row ) {
			$product_id = (int) $row['product_id'];
			$agotado    = function_exists( 'get_post_meta' )
				&& StockStatus::OUT_OF_STOCK === (string) get_post_meta( $product_id, '_stock_status', true );

			if ( 'publish' !== get_post_status( $product_id ) || $agotado ) {
				$drop[] = (int) $row['id'];
				continue;
			}
			$keep[] = $row;
		}

		$this->queue->drop( $drop, DigestQueue::DROP_UNPUBLISHED );

		return $keep;
	}

	/**
	 * Agrupa por serie, ordena por tomo descendente y corta al máximo de bloques.
	 *
	 * DOS COSAS DISTINTAS QUE EL DOCBLOCK ANTERIOR CONFUNDÍA:
	 *
	 *  - Las SERIES que no caben en el correo (más de `max_series_per_email`)
	 *    quedan PENDING y salen en el siguiente. Eso era —y sigue siendo— cierto.
	 *  - Los TOMOS de más de una MISMA serie NO quedan pending: los absorbe el
	 *    bloque del tomo más nuevo, que es lo correcto (33 tomos de Fire Force el
	 *    mismo jueves son un alta de catálogo, no 33 novedades). Lo que estaba
	 *    mal es que se marcaban `sent` sin haberse nombrado nunca. Ahora se
	 *    devuelven aparte, en `coalesced`, y acaban en
	 *    {@see DigestQueue::STATUS_COALESCED}.
	 *
	 * @param array<int, array<string, mixed>> $rows    Filas pendientes ya filtradas.
	 * @param int                              $user_id Cliente actual.
	 * @return array{groups: array<int, array<string, mixed>>, ids: array<int, int>, coalesced: array<int, int>}
	 */
	private function plan_groups( array $rows, int $user_id = 0 ): array {
		$by_term = array();
		foreach ( $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$volume  = (int) $row['volume'];
			$row_id  = (int) $row['id'];

			if ( ! isset( $by_term[ $term_id ] ) || $volume > (int) $by_term[ $term_id ]['volume'] ) {
				$by_term[ $term_id ] = array(
					'term_id'    => $term_id,
					'volume'     => $volume,
					'product_id' => (int) $row['product_id'],
					// La fila que el correo va a nombrar. Las otras de esta serie
					// se absorben, y sin guardar cuál es no hay forma de saber
					// después a cuál se avisó de verdad.
					'row_id'     => $row_id,
					'ids'        => $by_term[ $term_id ]['ids'] ?? array(),
					// fila ⇒ tomo. Anclado por ID de fila y no por número: dos
					// productos distintos pueden llevar el MISMO tomo (una
					// reedición) y la clave de idempotencia es por producto, así
					// que la segunda fila entra igual y hay que distinguirla. El
					// duplicado de NÚMERO lo colapsa `VolumePhrase::normalize()`.
					'volumes'    => $by_term[ $term_id ]['volumes'] ?? array(),
				);
			}

			$by_term[ $term_id ]['ids'][]              = $row_id;
			$by_term[ $term_id ]['volumes'][ $row_id ] = $volume;
		}

		uasort(
			$by_term,
			static fn ( array $a, array $b ): int => $b['volume'] <=> $a['volume']
		);

		$by_term = array_slice( $by_term, 0, SeriesFollow::max_series_per_email(), true );

		$groups    = array();
		$ids       = array();
		$coalesced = array();
		foreach ( $by_term as $entry ) {
			// Qué filas se absorben (para `coalesce()`) y qué tomos son (para el
			// correo) es la MISMA comparación contra `row_id`. Una sola pasada, y
			// el conjunto de absorbidas queda idéntico al de antes porque el
			// camino del claim (`ids`) no se toca.
			$absorbed = array();
			foreach ( $entry['volumes'] as $fila_id => $tomo ) {
				if ( (int) $fila_id !== (int) $entry['row_id'] ) {
					$absorbed[ (int) $fila_id ] = (int) $tomo;
				}
			}

			$is_gap = $this->user_owns_higher_volume_in_series(
				$user_id,
				(int) $entry['term_id'],
				(int) $entry['volume']
			);

			$group = $this->build_group(
				$entry['term_id'],
				$entry['product_id'],
				(int) $entry['volume'],
				array_values( $absorbed ),
				$is_gap
			);
			if ( null === $group ) {
				continue;
			}
			$groups[]  = $group;
			$ids       = array_merge( $ids, $entry['ids'] );
			$coalesced = array_merge( $coalesced, array_keys( $absorbed ) );
		}

		return array(
			'groups'    => $groups,
			'ids'       => $ids,
			'coalesced' => $coalesced,
		);
	}

	/**
	 * Determina si el tomo anunciado es un hueco (`< max_owned`) para este
	 * cliente en esa serie.
	 *
	 * @param int $user_id Cliente.
	 * @param int $term_id Serie (`pa_serie`).
	 * @param int $volume  Tomo anunciado.
	 * @return bool
	 */
	private function user_owns_higher_volume_in_series( int $user_id, int $term_id, int $volume ): bool {
		if ( $user_id < 1 || $term_id < 1 || $volume < 1 || ! function_exists( 'get_the_title' ) ) {
			return false;
		}

		$owned_result = $this->collection->owned_product_ids_result( $user_id );
		$owned_ids    = $owned_result['product_ids'] ?? array();
		if ( array() === $owned_ids ) {
			return false;
		}

		foreach ( $owned_ids as $owned_id ) {
			$owned_id = (int) $owned_id;
			if ( function_exists( 'get_the_terms' ) ) {
				try {
					$terms = get_the_terms( $owned_id, SeriesCatalogEligibility::TAXONOMY );
				} catch ( \Throwable $error ) {
					$terms = array();
				}
				if ( is_array( $terms ) && array() !== $terms ) {
					$matches_term = false;
					foreach ( $terms as $t ) {
						if ( is_object( $t ) && isset( $t->term_id ) && (int) $t->term_id === $term_id ) {
							$matches_term = true;
							break;
						}
					}
					if ( ! $matches_term ) {
						continue;
					}
				}
			}

			try {
				$raw_title = get_the_title( $owned_id );
			} catch ( \Throwable $error ) {
				$raw_title = '';
			}
			if ( ! is_string( $raw_title ) || '' === $raw_title ) {
				continue;
			}

			$owned_number = VolumeTitle::extract_number( $raw_title );
			if ( null !== $owned_number && $owned_number > $volume ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Un bloque del correo.
	 *
	 * @param int            $term_id    Serie.
	 * @param int            $product_id Tomo más nuevo de esa serie.
	 * @param int            $volume     Número del tomo.
	 * @param array<int,int> $absorbed   Tomos absorbidos en el mismo bloque.
	 * @param bool           $is_gap     True si cierra un hueco (< max_owned) en la colección del cliente.
	 * @return array<string, mixed>|null
	 */
	private function build_group( int $term_id, int $product_id, int $volume, array $absorbed = array(), bool $is_gap = false ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$term = function_exists( 'get_term' ) ? get_term( $term_id, SeriesCatalogEligibility::TAXONOMY ) : null;
		$name = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '';

		$absorbed[]         = $volume;
		$tomos              = VolumePhrase::normalize( $absorbed );
		$cutoff             = $this->cutoff->for_product( $product );
		$availability_state = Availability::of( $product );
		$availability_label = Availability::label_of( $product );

		if ( Availability::PREVENTA === $availability_state ) {
			$timeframe_copy = '' !== $cutoff
				? $cutoff
				: 'Reserva anticipada · Si reservas hoy, se despacha apenas llegue el lanzamiento a bodega.';
		} elseif ( Availability::A_PEDIDO === $availability_state ) {
			$timeframe_copy = '' !== $cutoff
				? $cutoff
				: 'Encargo a editorial · Si lo solicitas hoy, entra en el próximo corte de importación.';
		} else {
			$timeframe_copy = 'En stock en bodega · Si compras hoy, despachamos en 24 a 48 h hábiles.';
		}

		return array(
			'term_id'            => $term_id,
			'series_name'        => $name,
			'volume'             => $volume,
			'product_id'         => $product_id,
			'title'              => $product->get_name(),
			'url'                => (string) get_permalink( $product_id ),
			'price_html'         => $product->get_price_html(),
			// Usar la variante JPG utilizable por el consumidor de correo.
			'image_url'          => SocialImageDerivatives::email_url( (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' ) ),
			'cutoff'             => $cutoff,
			'availability'       => $availability_label,
			'availability_state' => $availability_state,
			'timeframe_copy'     => $timeframe_copy,
			'is_gap'             => $is_gap,
			// LA TANDA. `volumes` es el dato crudo —lo que una prueba puede
			// afirmar sin leer castellano, y lo que deja auditar «el correo dijo
			// 4 y la cola tenía 4»—; las otras dos son lo que imprime el correo,
			// resueltas AQUÍ igual que `cutoff`, sobre el MISMO array ya
			// normalizado. Un conteo que pueda contradecir a la frase es una
			// trampa para el próximo que lo lea, así que salen del mismo sitio.
			'volumes'            => $tomos,
			'volumes_count'      => count( $tomos ),
			'volumes_phrase'     => VolumePhrase::of( $tomos ),
		);
	}

	/**
	 * Aplica los descartes en PHP y encola lo que queda en UN solo
	 * `INSERT IGNORE`.
	 *
	 * @param int                                                          $product_id Tomo publicado.
	 * @param array{term_id:int, term_taxonomy_id:int, name:string, volume:int} $series     Serie resuelta.
	 * @param array<int, array<int, int>>                                  $followers  user_id ⇒ product_ids.
	 * @return void
	 */
	private function queue_for_followers( int $product_id, array $series, array $followers ): void {
		$all_products = array( $product_id );
		foreach ( $followers as $owned_ids ) {
			foreach ( $owned_ids as $owned_id ) {
				$all_products[] = $owned_id;
			}
		}

		// UN solo prime para todos los títulos: sin esto, `get_the_title()` de
		// cada tomo comprado sería un N+1 contra la base. El conjunto único está
		// acotado por el TAMAÑO DE LA SERIE, no por el número de compradores:
		// todos los productos vienen de la misma `pa_serie`, así que doscientos
		// clientes con veinte tomos cada uno siguen siendo ~cien ids distintos.
		_prime_post_caches( array_values( array_unique( $all_products ) ), false, false );

		FollowRegistry::prime( array_keys( $followers ) );

		$target_brands = $this->product_brand_ids( $product_id );
		$scope         = SeriesFollow::volume_scope();
		$target_volume = (int) $series['volume'];

		$rows = array();
		foreach ( $followers as $user_id => $owned_ids ) {
			$user_id = (int) $user_id;

			if ( in_array( $product_id, $owned_ids, true ) ) {
				continue; // Ya lo tiene.
			}

			$max_owned     = 0;
			$min_owned     = PHP_INT_MAX;
			$owned_volumes = array();

			foreach ( $owned_ids as $owned_id ) {
				$owned_id = (int) $owned_id;

				// Guardia editorial: si el tomo publicado y el comprado declaran
				// `product_brand` distintos (ej. Panini vs Ivrea), no mezclarlos.
				if ( array() !== $target_brands ) {
					$owned_brands = $this->product_brand_ids( $owned_id );
					if ( array() !== $owned_brands && array() === array_intersect( $target_brands, $owned_brands ) ) {
						continue;
					}
				}

				$number = VolumeTitle::extract_number( (string) get_the_title( $owned_id ) );
				if ( null !== $number && $number >= 1 ) {
					$owned_volumes[ $number ] = true;
					if ( $number > $max_owned ) {
						$max_owned = $number;
					}
					if ( $number < $min_owned ) {
						$min_owned = $number;
					}
				}
			}

			// Sin NINGÚN tomo numerado de la serie (sólo artbooks, especiales o
			// un Expansion Pass) o si ya posee ese número de tomo, no aplica.
			if ( $max_owned < 1 || isset( $owned_volumes[ $target_volume ] ) ) {
				continue;
			}

			$es_nuevo = $target_volume > $max_owned;
			$es_hueco = $target_volume > $min_owned && $target_volume < $max_owned;

			if ( SeriesFollow::SCOPE_ONLY_NEW === $scope && ! $es_nuevo ) {
				continue;
			}

			if ( SeriesFollow::SCOPE_NEW_AND_GAPS === $scope && ! $es_nuevo && ! $es_hueco ) {
				continue;
			}

			if ( FollowRegistry::is_opted_out( $user_id, $series['term_id'] ) ) {
				continue;
			}

			$rows[] = array(
				'user_id'    => $user_id,
				'product_id' => $product_id,
				'term_id'    => $series['term_id'],
				'volume'     => $target_volume,
			);
		}

		if ( array() !== $rows ) {
			$this->queue->queue( $rows );
		}
	}

	/**
	 * Devuelve los `term_id` de `product_brand` del producto, o `array()` si no
	 * tiene marca asignada.
	 *
	 * @param int $product_id Producto.
	 * @return array<int, int>
	 */
	private function product_brand_ids( int $product_id ): array {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$terms = get_the_terms( $product_id, 'product_brand' );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->term_id ) && (int) $term->term_id > 0 ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Re-valida el producto AHORA: pudo despublicarse, agotarse, ocultarse del
	 * catálogo o ser una republicación de algo viejo entre el encolado y el
	 * worker.
	 *
	 * Un tomo agotado (`outofstock`) NO se anuncia: el aviso promete un tomo que
	 * se puede comprar o encargar hoy (`instock` u `onbackorder`). Cuando más
	 * adelante pase a «Disponible», «A pedido» o «Preventa», `PublishListener`
	 * vuelve a despertar el escaneo con `META_RESTOCKED_AT`.
	 *
	 * @param int $product_id Producto a validar.
	 * @return bool
	 */
	private function product_is_announceable( int $product_id ): bool {
		if ( 'publish' !== get_post_status( $product_id ) ) {
			return false;
		}

		if ( function_exists( 'get_post_meta' )
			&& StockStatus::OUT_OF_STOCK === (string) get_post_meta( $product_id, '_stock_status', true ) ) {
			return false;
		}

		if ( function_exists( 'has_term' )
			&& has_term( SeriesCatalogEligibility::HIDDEN_FROM_CATALOG, SeriesCatalogEligibility::VISIBILITY_TAXONOMY, $product_id ) ) {
			return false;
		}

		$published    = strtotime( (string) get_post_field( 'post_date_gmt', $product_id ) . ' +00:00' );
		$restocked_at = function_exists( 'get_post_meta' )
			? (int) get_post_meta( $product_id, PublishListener::META_RESTOCKED_AT, true )
			: 0;

		if ( false === $published ) {
			return true; // Sin fecha legible no se puede afirmar que sea viejo.
		}

		$effective_ts = max( $published, $restocked_at );

		return ( time() - $effective_ts ) <= ( self::NOVELTY_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Auto-reagendado con el cursor en los args: una serie con miles de
	 * compradores no revienta un worker. Action Scheduler no trae rate-limit.
	 *
	 * @param int                         $product_id Producto en curso.
	 * @param array<int, array<int, int>> $followers  Página recién procesada.
	 * @return void
	 */
	private function maybe_paginate( int $product_id, array $followers ): void {
		if ( count( $followers ) < self::FOLLOWERS_PER_PAGE ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$user_ids = array_map( 'intval', array_keys( $followers ) );

		as_schedule_single_action(
			time() + self::SCAN_GAP_SECONDS,
			SeriesFollow::HOOK_SCAN,
			array(
				'product_id' => $product_id,
				'cursor'     => max( $user_ids ),
			),
			SeriesFollow::AS_GROUP
		);
	}

	/**
	 * Reintento acotado a +60s mientras `WC_Email` no sea cargable.
	 *
	 * El techo no es opcional: este módulo ya pagó una vez el precio de una
	 * acción re-agendándose «por si acaso» para siempre (ver el docblock de
	 * `BackInStock\NotificationDispatcher`). Agotado el techo, la cola queda
	 * PENDING y la retoma el próximo `scan()` o el guardado del ajuste.
	 *
	 * @param int $mailer_try Reintentos ya gastados por esta cadena.
	 * @return void
	 */
	private function reschedule_unready_mailer( int $mailer_try ): void {
		if ( $mailer_try >= self::MAX_MAILER_RETRIES ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + self::MAILER_RETRY_SECONDS,
			SeriesFollow::HOOK_DIGEST,
			array( 'mailer_try' => $mailer_try + 1 ),
			SeriesFollow::AS_GROUP
		);
	}

	/**
	 * Reintento acotado mientras el correo del digest está deshabilitado en WC.
	 *
	 *  (): sin esto, apagar el correo en la pantalla de
	 * WooCommerce (no el interruptor de Akibara, que sí tiene su propio
	 * `on_setting_saved()`) congelaba la cadena para siempre y sin traza — nada
	 * la retomaba salvo publicar un tomo nuevo. Mismo patrón, mismo gap (15 min)
	 * y mismo techo (cuatro intentos ≈ una hora) que
	 * `BackInStock\NotificationDispatcher::reschedule_paused()`.
	 *
	 * OJO: agotado el techo, las filas quedan PENDING
	 * esperando el próximo tomo publicado (`scan()` vuelve a agendar el digest
	 * desde cero) o que alguien apague y vuelva a encender el interruptor
	 * PROPIO de Akibara — el que sí dispara `on_setting_saved()` de arriba.
	 * Reactivar el correo en WooCommerce → Emails, en cambio, NO hace nada: no
	 * hay ningún `update_option_*` ni `woocommerce_settings_saved` enganchado a
	 * ese ajuste (a diferencia de `OPTION_ENABLED`), así que ese clic no reagenda
	 * la cadena. Es justo la confusión de dos interruptores que ya costó tres
	 * días sin correo (ver la justificación de) — no la repitamos
	 * aquí con un comentario que sugiera lo contrario.
	 *
	 * @param int $email_try Reintentos ya gastados por esta cadena.
	 * @return void
	 */
	private function reschedule_disabled_email( int $email_try ): void {
		if ( $email_try >= self::MAX_EMAIL_DISABLED_RETRIES ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + self::EMAIL_DISABLED_RETRY_SECONDS,
			SeriesFollow::HOOK_DIGEST,
			// Los dos contadores son argumentos posicionales. Action Scheduler aplica
			// array_values(args), por lo que omitir el primero desplazaría email_try a
			// mailer_try y reiniciaría el presupuesto de correo.

			array(
				'mailer_try' => 0,
				'email_try'  => $email_try + 1,
			),
			SeriesFollow::AS_GROUP
		);
	}

	/**
	 * Coalescencia de la corrida: `$args = null` matchea CUALQUIER args, así que
	 * los cincuenta scans de una tarde de import ven la MISMA corrida pendiente
	 * y ninguno agenda una segunda.
	 *
	 * LA CORRIDA SE CONTABA A SÍ MISMA Y ROMPÍA SU PROPIA CADENA.
	 *
	 * Antes preguntaba con `as_next_scheduled_action()`, y esa función devuelve
	 * `true` en cuanto hay una acción del mismo hook **en curso**
	 * (`functions.php:362-366` de Action Scheduler: consulta STATUS_RUNNING antes
	 * que STATUS_PENDING y devuelve `true` sin mirar más). Cuando este método se
	 * llama desde el final de `run()`, la acción en curso ES la propia corrida:
	 * la guarda salía por la puerta de atrás y no se agendaba nada.
	 *
	 * El daño no era teórico. En producción, el, había **tres corridas
	 * del digest en toda la vida del módulo** y ninguna encadenada. Una fila que
	 * quedaba pendiente por el tope semanal se dormía hasta que alguien publicara
	 * otro tomo — que es el único otro camino que agenda una corrida.
	 *
	 * Se pregunta sólo por lo PENDIENTE, que es la misma distinción que el
	 * buscador ya documenta en `Indexer::has_pending_action()`: una acción en
	 * curso no representa el trabajo que viene después de ella.
	 *
	 * Sin Action Scheduler no hay ventana de acumulación posible y el módulo no
	 * envía: WooCommerce lo trae siempre, así que ese entorno es el de las
	 * pruebas, no el de producción.
	 *
	 * @param int $delay Segundos hasta la corrida.
	 * @return void
	 */
	private function schedule_digest( int $delay ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$pendientes = as_get_scheduled_actions(
			array(
				'hook'     => SeriesFollow::HOOK_DIGEST,
				'group'    => SeriesFollow::AS_GROUP,
				'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! empty( $pendientes ) ) {
			return;
		}

		as_schedule_single_action(
			time() + max( 0, $delay ),
			SeriesFollow::HOOK_DIGEST,
			array(),
			SeriesFollow::AS_GROUP
		);
	}

	/**
	 * Resuelve la instancia registrada en el mailer de WC. Lookup por
	 * `instanceof` y no por clave: a prueba de colisiones en
	 * `woocommerce_email_classes`.
	 *
	 * @return SeriesFollowEmail|null
	 */
	private function email(): ?SeriesFollowEmail {
		$email = \Akibara\Business\Services\MailerBoot::from_mailer( SeriesFollowEmail::class, $this->email_factory );
		return $email instanceof SeriesFollowEmail ? $email : null;
	}
}
