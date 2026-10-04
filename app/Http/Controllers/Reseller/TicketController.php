<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Support tickets raised by a reseller. */
class TicketController
{
    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('reseller.tickets.index', [
            'tickets' => Ticket::query()
                ->where('id_user', $user->id)
                ->orderByDesc('id')
                ->paginate((int) config('telecom.ui.per_page', 25)),
        ]);
    }

    public function create(): View
    {
        return view('reseller.tickets.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $ticket = DB::transaction(function () use ($user, $validated, $request): Ticket {
            $ticket = Ticket::create([
                'id_user' => (int) $user->id,
                'subject' => $validated['subject'],
                'c_date'  => (string) now()->getTimestamp(),
                'status'  => 0,
            ]);

            TicketMessage::create([
                'id_user'    => (int) $user->id,
                'msg'        => $validated['message'],
                'ip'         => (string) $request->ip(),
                'date'       => now()->format('Y-m-d'),
                'time'       => now()->format('H:i:s'),
                'ctime'      => (string) now()->getTimestamp(),
                'tricket_id' => (int) $ticket->id,
            ]);

            return $ticket;
        });

        return redirect()
            ->route('reseller.tickets.show', $ticket)
            ->with('status', 'Ticket opened. Support will reply here.');
    }

    public function show(Request $request, Ticket $ticket): View
    {
        // A ticket belongs to exactly one reseller; anything else is a 404.
        abort_unless((int) $ticket->id_user === (int) $this->user($request)->id, 404);

        return view('reseller.tickets.show', [
            'ticket'   => $ticket,
            'messages' => TicketMessage::query()
                ->where('tricket_id', $ticket->id)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless((int) $ticket->id_user === (int) $this->user($request)->id, 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        TicketMessage::create([
            'id_user'    => (int) $this->user($request)->id,
            'msg'        => $validated['message'],
            'ip'         => (string) $request->ip(),
            'date'       => now()->format('Y-m-d'),
            'time'       => now()->format('H:i:s'),
            'ctime'      => (string) now()->getTimestamp(),
            'tricket_id' => (int) $ticket->id,
        ]);

        $ticket->forceFill(['status' => 0])->save();

        return back()->with('status', 'Reply sent.');
    }

    private function user(Request $request): Reseller
    {
        $user = $request->attributes->get('current_reseller');
        abort_unless($user instanceof Reseller, 403);

        return $user;
    }
}
