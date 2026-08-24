<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Request;

/**
 * A moderator's correction of a scan verdict, sent to POST /scan/feedback.
 *
 * This is the only way a wrong classification gets back into the model. A
 * `spam` label also trains the platform's Bayes classifier and stores a
 * sample scoped to that platform, so it is worth wiring into whatever
 * "mark as spam" button the host already has.
 */
final class FeedbackRequest
{
    public const LABEL_SPAM = 'spam';
    public const LABEL_HAM = 'ham';

    /**
     * @param string $submissionId UUID from CheckSpamResponse::getSubmissionId().
     * @param string $correctLabel LABEL_SPAM or LABEL_HAM. The backend rejects anything else with 422.
     * @param ?string $reviewerNotes Free-text note for the audit trail.
     */
    public function __construct(
        public readonly string $submissionId,
        public readonly string $correctLabel,
        public readonly ?string $reviewerNotes = null,
    ) {
    }

    public static function spam(string $submissionId, ?string $reviewerNotes = null): self
    {
        return new self($submissionId, self::LABEL_SPAM, $reviewerNotes);
    }

    public static function ham(string $submissionId, ?string $reviewerNotes = null): self
    {
        return new self($submissionId, self::LABEL_HAM, $reviewerNotes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'submission_id' => $this->submissionId,
            'correct_label' => $this->correctLabel,
        ];

        if ($this->reviewerNotes !== null && $this->reviewerNotes !== '') {
            $data['reviewer_notes'] = $this->reviewerNotes;
        }

        return $data;
    }
}
