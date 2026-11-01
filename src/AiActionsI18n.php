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
            'title'        => 'AI-Assistent',
            'open'         => 'AI-Assistent öffnen',
            'close'        => 'Schließen',
            'new'          => 'Neu',
            'send'         => 'Senden',
            'placeholder'  => 'Frage oder Anweisung zu diesem Ticket …',
            'empty'        => 'Frag etwas zu diesem Ticket oder sag, was geändert werden soll, z. B. „Setze die Fälligkeit auf morgen“. Änderungen passieren erst nach deinem Klick auf „Ausführen“.',
            'no_ticket'    => 'Öffne ein Ticket, um hier mit der AI zu arbeiten.',
            'ticket'       => 'Ticket',
            'you'          => 'Du',
            'ai'           => 'AI',
            'run'          => 'Ausführen',
            'discard'      => 'Verwerfen',
            'thinking'     => 'Denkt nach …',
            'running'      => 'Wird ausgeführt …',
            'preview'      => 'Geplante Änderungen',
            'turns_left'   => 'Noch %d Nachrichten in diesem Gespräch',
            'states'       => array(
                'done'       => 'Ausgeführt',
                'discarded'  => 'Verworfen',
                'expired'    => 'Abgelaufen',
                'superseded' => 'Nicht ausgeführt (neue Nachricht)',
            ),
            'failed'       => 'fehlgeschlagen',
            'due_date'     => 'Fälligkeit',
            'labels'       => array(
                'set_due_date' => 'Fälligkeit',
                'set_priority' => 'Priorität',
                'assign_agent' => 'Zuständig',
                'assign_team'  => 'Team',
                'transfer'     => 'Abteilung',
                'set_status'   => 'Status',
            ),
            'weekday'      => array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'),
            'note_title'   => 'AI-Aktion',
            'note_request' => 'Anfrage',
            'note_changes' => 'Änderungen',
            'note_ok'      => 'erledigt',
            'errors'       => array(
                'generic'           => 'Das hat nicht geklappt. Bitte erneut versuchen.',
                'not_configured'    => 'Die AI-Anbindung ist nicht eingerichtet. Bitte an den Admin wenden.',
                'expired'           => 'Vorschau abgelaufen, bitte neu anfragen.',
                'invalid_prompt'    => 'Bitte eine Nachricht mit höchstens 2000 Zeichen eingeben.',
                'forbidden'         => 'keine Berechtigung',
                'not_open'          => 'Ticket ist geschlossen',
                'rejected'          => 'von osTicket abgelehnt',
                'csrf'              => 'Sitzung abgelaufen, bitte die Seite neu laden.',
                'unavailable'       => 'Die AI ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.',
                'refused'           => 'Die AI hat die Anfrage abgelehnt.',
                'conversation_full' => 'Dieses Gespräch ist voll. Mit „Neu“ beginnst du ein frisches.',
                'not_found'         => 'Ticket nicht gefunden oder kein Zugriff.',
            ),
        ) : array(
            'title'        => 'AI assistant',
            'open'         => 'Open the AI assistant',
            'close'        => 'Close',
            'new'          => 'New',
            'send'         => 'Send',
            'placeholder'  => 'Question or instruction about this ticket …',
            'empty'        => 'Ask something about this ticket or say what to change, e.g. "Set the due date to tomorrow". Changes only happen after you click "Run".',
            'no_ticket'    => 'Open a ticket to work with the AI here.',
            'ticket'       => 'Ticket',
            'you'          => 'You',
            'ai'           => 'AI',
            'run'          => 'Run',
            'discard'      => 'Discard',
            'thinking'     => 'Thinking …',
            'running'      => 'Running …',
            'preview'      => 'Planned changes',
            'turns_left'   => '%d messages left in this conversation',
            'states'       => array(
                'done'       => 'Done',
                'discarded'  => 'Discarded',
                'expired'    => 'Expired',
                'superseded' => 'Not run (new message)',
            ),
            'failed'       => 'failed',
            'due_date'     => 'Due date',
            'labels'       => array(
                'set_due_date' => 'Due date',
                'set_priority' => 'Priority',
                'assign_agent' => 'Assigned to',
                'assign_team'  => 'Team',
                'transfer'     => 'Department',
                'set_status'   => 'Status',
            ),
            'weekday'      => array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'),
            'note_title'   => 'AI action',
            'note_request' => 'Request',
            'note_changes' => 'Changes',
            'note_ok'      => 'done',
            'errors'       => array(
                'generic'           => 'That didn\'t work. Please try again.',
                'not_configured'    => 'The AI connection is not set up. Please contact your admin.',
                'expired'           => 'Preview expired, please ask again.',
                'invalid_prompt'    => 'Please enter a message of at most 2000 characters.',
                'forbidden'         => 'permission denied',
                'not_open'          => 'ticket is closed',
                'rejected'          => 'rejected by osTicket',
                'csrf'              => 'Session expired, please reload the page.',
                'unavailable'       => 'The AI is not reachable right now. Please try again in a moment.',
                'refused'           => 'The AI declined the request.',
                'conversation_full' => 'This conversation is full. "New" starts a fresh one.',
                'not_found'         => 'Ticket not found or no access.',
            ),
        );
    }
}
