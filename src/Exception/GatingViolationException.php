<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Exception;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;

/**
 * Exception thrown when gating blocks a moderation transition.
 */
class GatingViolationException extends \Exception {

  /**
   * Constructs a GatingViolationException.
   *
   * @param string $message
   *   The violation message.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(
    string $message,
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}

