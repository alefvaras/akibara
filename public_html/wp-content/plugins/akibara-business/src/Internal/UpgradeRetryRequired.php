<?php
/**
 * Señala que una tarea de upgrade idempotente debe volver a la cola.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Internal;

defined( 'ABSPATH' ) || exit;

final class UpgradeRetryRequired extends \RuntimeException {
}
