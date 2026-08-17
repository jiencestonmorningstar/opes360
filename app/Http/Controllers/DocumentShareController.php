<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AbortsForSuspendedCompany;
use App\Models\BusinessDocumentShare;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentSharing;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use App\Support\Pdf;
use App\Support\Watermarks;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The public link behind "share this document" — no account, no session.
 *
 * Same shape as VerificationController and SignatureController, for the same
 * reason: the visitor is not one of the business's users, so there is no
 * current company until the token supplies one, and everything resolves
 * *as* that company for the life of the request.
 */
class DocumentShareController extends Controller
{
    use AbortsForSuspendedCompany;

    protected const UNLOCKED_SESSION_KEY = 'unlocked_shares';

    public function show(Request $request, string $token, DocumentComposer $composer)
    {
        $share = $this->findShare($token);

        if ($share === null) {
            return response()->view('shares.show', ['verdict' => 'unknown'], 404);
        }

        if (! $share->isLive()) {
            return response()->view('shares.show', [
                'verdict' => $share->isRevoked() ? 'revoked' : 'expired',
                'share' => $share,
            ]);
        }

        // Gone or suspended, the link goes dark: never CurrentCompany::as(null),
        // and a business suspended for abuse does not keep serving its papers.
        $company = Company::find($share->company_id);

        abort_if($company === null, 404);
        $this->abortIfSuspended($company);

        return app(CurrentCompany::class)->as($company, function () use ($request, $share, $company, $composer) {
            if ($share->isPasswordProtected() && ! $this->isUnlocked($request, $share)) {
                return response()->view('shares.show', [
                    'verdict' => 'locked',
                    'share' => $share,
                    'company' => $company,
                ]);
            }

            app(DocumentSharing::class)->recordAccess($share, $request->ip(), $request->userAgent());

            /*
             * Phase 5 — "Download PDF" on the external copy. Everything is
             * resolved inside CurrentCompany::as($company), so the rendered
             * sheet can only ever carry this share's own tenant: the token
             * names the company, never the visitor's session. The marks are
             * the same as the on-screen copy — COPY for an issued document
             * (never presented as the original), and the confidential footer
             * naming the share link itself.
             */
            if ($request->query('format') === 'pdf') {
                abort_unless((bool) $share->allow_download, 403);

                $document = $share->document;

                return app(Pdf::class)->download('print.paper', [
                    'watermark' => Watermarks::statusMark($document, isCopy: true),
                    'confidentialFooter' => Watermarks::confidentialFooter(
                        $document,
                        $company,
                        Watermarks::shareIdentity($share),
                    ),
                    'paper' => $document,
                    'company' => $company,
                    'bodyHtml' => $composer->toHtml($document->body),
                    'notice' => ($document->template()['binding'] ?? false)
                        ? DocumentTemplates::reviewNotice()
                        : null,
                    'qrSvg' => null,
                    'autoprint' => false,
                ], Pdf::filename($document->reference, $document->title));
            }

            return response()->view('shares.show', [
                'verdict' => 'found',
                'share' => $share,
                'document' => $share->document,
                'company' => $company,
                'bodyHtml' => $composer->toHtml($share->document->body),
            ]);
        });
    }

    public function unlock(Request $request, string $token)
    {
        $share = $this->findShare($token);

        abort_if($share === null || ! $share->isLive(), 404);

        $data = $request->validate(['password' => ['required', 'string']]);

        if (! $share->checkPassword($data['password'])) {
            return back()->withErrors(['password' => 'That password is not correct.']);
        }

        $unlocked = $request->session()->get(self::UNLOCKED_SESSION_KEY, []);
        $unlocked[] = $share->id;
        $request->session()->put(self::UNLOCKED_SESSION_KEY, array_unique($unlocked));

        return redirect()->route('shares.show', $token);
    }

    protected function isUnlocked(Request $request, BusinessDocumentShare $share): bool
    {
        return in_array($share->id, $request->session()->get(self::UNLOCKED_SESSION_KEY, []), true);
    }

    protected function findShare(string $token): ?BusinessDocumentShare
    {
        return BusinessDocumentShare::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('share_token', $token)
            ->first();
    }
}
