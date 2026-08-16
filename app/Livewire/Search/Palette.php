<?php

namespace App\Livewire\Search;

use App\Search\GlobalSearch;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The global search palette in the top bar. Ctrl/Cmd-K opens it; the query is
 * debounced client-side and answered from the search_entries index, filtered
 * through the searcher's own gates — see GlobalSearch::query() for the rules.
 *
 * Deliberately not audited: typing in a search box is navigation, not a
 * sensitive read, and logging it would bury the audit trail in noise.
 */
class Palette extends Component
{
    public string $query = '';

    public function render(): View
    {
        $user = auth()->user();
        $company = app(CurrentCompany::class)->get();

        $groups = ($user && $company)
            ? GlobalSearch::query($user, $company, $this->query)
            : [];

        return view('livewire.search.palette', ['groups' => $groups]);
    }
}
