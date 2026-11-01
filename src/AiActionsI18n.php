<?php

declare(strict_types=1);

/**
 * Interface strings. German for agents whose language starts with "de",
 * English otherwise.
 */
final class AiActionsI18n
{
    /** @return array<string, mixed> */
    public static function strings(string $lang): array
    {
        $de = stripos($lang, 'de') === 0;
        return $de ? array(
            'title'        => 'AI-Aktion',
            'placeholder'  => 'z. B. „Setze die Fälligkeit auf morgen“ oder „Worum geht es in diesem Ticket?“',
            'plan'         => 'Planen',
            'run'          => 'Ausführen',
            'discard'      => 'Verwerfen',
            'thinking'     => 'Wird geplant …',
            'running'      => 'Wird ausgeführt …',
            'preview'      => 'Geplante Änderungen, erst nach „Ausführen“ wirksam:',
            'no_changes'   => 'Keine Änderungen geplant.',
            'done'         => 'Ausgeführt.',
            'failed'       => 'fehlgeschlagen',
            'due_date'     => 'Fälligkeit',
            'weekday'      => array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'),
            'note_title'   => 'AI-Aktion',
            'note_request' => 'Anfrage',
            'note_changes' => 'Änderungen',
            'note_ok'      => 'erledigt',
            'errors'       => array(
                'generic'         => 'Das hat nicht geklappt. Bitte erneut versuchen.',
                'not_configured'  => 'Die AI-Anbindung ist nicht eingerichtet. Bitte an den Admin wenden.',
                'expired'         => 'Vorschau abgelaufen, bitte neu anfragen.',
                'invalid_prompt'  => 'Bitte eine Anfrage mit höchstens 2000 Zeichen eingeben.',
                'forbidden'       => 'keine Berechtigung',
                'not_open'        => 'Ticket ist geschlossen',
                'rejected'        => 'von osTicket abgelehnt',
                'csrf'            => 'Sitzung abgelaufen, bitte die Seite neu laden.',
            ),
        ) : array(
            'title'        => 'AI action',
            'placeholder'  => 'e.g. "Set the due date to tomorrow" or "What is this ticket about?"',
            'plan'         => 'Plan',
            'run'          => 'Run',
            'discard'      => 'Discard',
            'thinking'     => 'Planning …',
            'running'      => 'Running …',
            'preview'      => 'Planned changes, applied only after "Run":',
            'no_changes'   => 'No changes planned.',
            'done'         => 'Done.',
            'failed'       => 'failed',
            'due_date'     => 'Due date',
            'weekday'      => array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'),
            'note_title'   => 'AI action',
            'note_request' => 'Request',
            'note_changes' => 'Changes',
            'note_ok'      => 'done',
            'errors'       => array(
                'generic'         => 'That didn\'t work. Please try again.',
                'not_configured'  => 'The AI connection is not set up. Please contact your admin.',
                'expired'         => 'Preview expired, please ask again.',
                'invalid_prompt'  => 'Please enter a request of at most 2000 characters.',
                'forbidden'       => 'permission denied',
                'not_open'        => 'ticket is closed',
                'rejected'        => 'rejected by osTicket',
                'csrf'            => 'Session expired, please reload the page.',
            ),
        );
    }
}
