<?php

namespace App\Services;

use App\Mail\InquiryReplyMail;
use App\Models\ActivityLog;
use App\Models\Inquiry;
use App\Models\Notification;
use App\Models\User;
use RuntimeException;

/**
 * Everything that happens to an enquiry after it is written.
 *
 * Modelled on UserAccountService: the controllers validate and answer, and
 * every write lands here, so the audit entry, the notification and the email
 * that belong with an action cannot be forgotten by one call site and
 * remembered by another.
 *
 * The one rule worth stating out loud is in reply(): the status only moves to
 * Responded once the mailer has accepted the message. An enquiry marked
 * answered that nobody actually answered is worse than one still marked New.
 */
class InquiryService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly EmailService $email,
    ) {}

    /**
     * Store what somebody wrote on the Contact page.
     *
     * The visitor has no account and nothing is created for them - no client,
     * no project, no link to either. The record is the message.
     *
     * @param  array{name: string, email: string, subject: string, message: string}  $data
     */
    public function record(array $data): Inquiry
    {
        $inquiry = Inquiry::create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'status' => Inquiry::STATUS_NEW,
        ]);

        // Nobody is signed in, so the trail records the name they gave - the
        // same way a failed sign-in records the address that was tried.
        $this->activityLogger->recordAnonymous(
            ActivityLog::CONTACT_INQUIRY_SENT,
            $inquiry->name,
            null,
            sprintf(
                '%s - a website enquiry was sent by %s (%s): %s',
                $inquiry->code(),
                $inquiry->name,
                $inquiry->email,
                $inquiry->subject
            )
        );

        // The existing bell, pointed at the tab the enquiry appears in. No
        // separate alerting for inquiries - administrators already read this.
        $this->notifications->deliver(
            $this->notifications->administrators(),
            'New website enquiry',
            sprintf('%s wrote in about %s.', $inquiry->name, $inquiry->subject),
            Notification::MODULE_INQUIRIES,
            $inquiry,
            $this->inquiryLink($inquiry)
        );

        return $inquiry;
    }

    /**
     * Move an enquiry to another status.
     *
     * No sequence is enforced. New straight to Closed is a real answer to an
     * enquiry that needs none, and refusing it would only teach people to
     * click twice.
     */
    public function changeStatus(Inquiry $inquiry, string $status): Inquiry
    {
        if (! array_key_exists($status, Inquiry::STATUSES)) {
            throw new RuntimeException('That is not a status an enquiry can be in.');
        }

        if ($inquiry->status === $status) {
            throw new RuntimeException('That enquiry is already '.Inquiry::STATUSES[$status].'.');
        }

        $previous = $inquiry->statusLabel();

        $inquiry->status = $status;
        $inquiry->save();

        $this->activityLogger->record(
            ActivityLog::INQUIRY_STATUS_CHANGED,
            null,
            sprintf(
                '%s - status changed from %s to %s (%s)',
                $inquiry->code(),
                $previous,
                $inquiry->statusLabel(),
                $inquiry->subject
            ),
            $inquiry
        );

        return $inquiry;
    }

    /**
     * Answer an enquiry by email, and record what was said.
     *
     * The order matters. Nothing is written until the mailer has accepted the
     * message, so a failure leaves the enquiry exactly as it was and available
     * for another attempt.
     */
    public function reply(Inquiry $inquiry, string $body, User $sender): Inquiry
    {
        if ($inquiry->is_archived) {
            throw new RuntimeException('That enquiry is archived. Restore it before replying.');
        }

        if (! $this->email->isDeliverable()) {
            throw new RuntimeException(
                'Email is not configured on this system, so the reply could not be sent.'
            );
        }

        $body = trim($body);

        $sent = $this->email->send($inquiry->email, new InquiryReplyMail(
            $inquiry->name,
            $inquiry->subject,
            $body,
            $inquiry->message,
            $inquiry->code(),
        ));

        if (! $sent) {
            throw new RuntimeException(
                'The reply could not be sent. The enquiry has been left unchanged so it can be tried again.'
            );
        }

        $inquiry->reply_message = $body;
        $inquiry->replied_at = now();
        $inquiry->replied_by = $sender->id;
        // Replying is what Responded means, wherever the enquiry was before.
        $inquiry->status = Inquiry::STATUS_RESPONDED;
        $inquiry->save();

        $this->activityLogger->record(
            ActivityLog::INQUIRY_REPLY_SENT,
            null,
            sprintf(
                '%s - replied to %s (%s) about %s',
                $inquiry->code(),
                $inquiry->name,
                $inquiry->email,
                $inquiry->subject
            ),
            $inquiry
        );

        return $inquiry;
    }

    /**
     * Take an enquiry off the working list. Nothing is deleted.
     */
    public function archive(Inquiry $inquiry): Inquiry
    {
        if ($inquiry->is_archived) {
            throw new RuntimeException('That enquiry is already archived.');
        }

        $inquiry->is_archived = true;
        $inquiry->archived_at = now();
        // On the row as well as in the trail: the archive table names who did
        // it, and a table is joined rather than searched.
        $inquiry->archived_by = auth()->id();
        $inquiry->save();

        $this->activityLogger->record(
            ActivityLog::INQUIRY_ARCHIVED,
            null,
            sprintf('%s - archived (%s)', $inquiry->code(), $inquiry->subject),
            $inquiry
        );

        return $inquiry;
    }

    /**
     * Put an archived enquiry back on the working list, exactly as it was.
     */
    public function restore(Inquiry $inquiry): Inquiry
    {
        if (! $inquiry->is_archived) {
            throw new RuntimeException('That enquiry is not archived.');
        }

        $inquiry->is_archived = false;
        $inquiry->archived_at = null;
        $inquiry->archived_by = null;
        $inquiry->save();

        $this->activityLogger->record(
            ActivityLog::INQUIRY_RESTORED,
            null,
            sprintf('%s - restored (%s)', $inquiry->code(), $inquiry->subject),
            $inquiry
        );

        return $inquiry;
    }

    /**
     * Where the bell sends a reader: the Inquiries tab, with the enquiry
     * opened. A path rather than an absolute URL, like every other link the
     * notification service builds.
     */
    private function inquiryLink(Inquiry $inquiry): string
    {
        return route('super-admin.configuration.index', ['inquiry' => $inquiry->inquiry_id], false);
    }
}
