<?php

namespace App\Mail;

use App\Models\User;

/**
 * The outcome of a technician's request to change their specialties.
 *
 * Approved and rejected are one mailable because they differ only in the
 * verdict and in whether the specialties listed are the new set or the
 * unchanged one.
 */
class SpecialtyDecisionMail extends SystemMail
{
    public function __construct(
        public readonly User $technicianAccount,
        public readonly bool $approved,
        /** @var array<int, string> */
        public readonly array $specialties = [],
    ) {}

    protected function subjectLine(): string
    {
        return $this->approved
            ? 'Your specialty request has been approved'
            : 'Your specialty request has been declined';
    }

    protected function template(): string
    {
        return 'emails.specialty-decision';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'account' => $this->technicianAccount,
            'approved' => $this->approved,
            'specialties' => $this->specialties,
            'profileUrl' => route('profile.edit'),
        ];
    }
}
