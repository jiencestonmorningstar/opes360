{{--
    §2.17 — the watermark layer over the one print pipeline.

    Pure CSS on the existing views: a fixed, rotated status mark that repeats
    on every printed page, and a fixed footer line naming the paper's company
    and viewer. No PDF library, nothing server-rendered — it prints from the
    same browser dialog everything else does, which is what keeps it working
    on shared hosting.

    Expects: $watermark (?string — DRAFT / VOID / COPY) and
    $confidentialFooter (?string). Either may be null.
--}}
@if (($watermark ?? null) || ($confidentialFooter ?? null))
    <style>
        /* Fixed, so the mark lands on every page the print spans; behind
           nothing and through everything — pointer-events off keeps the
           screen preview clickable underneath it. */
        .wm-status {
            position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
            pointer-events: none; z-index: 40; overflow: hidden;
        }
        .wm-status span {
            transform: rotate(-32deg);
            font-size: 96pt; font-weight: 800; letter-spacing: 0.12em; white-space: nowrap;
            color: rgba(148, 163, 184, 0.28);
        }
        .wm-void span { color: rgba(190, 18, 60, 0.22); }
        .wm-foot {
            position: fixed; left: 0; right: 0; bottom: 2mm; text-align: center;
            font-size: 6.5pt; letter-spacing: 0.06em; color: rgba(100, 116, 139, 0.85);
            pointer-events: none; z-index: 40;
        }
        @media print {
            .wm-status span { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .wm-foot { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>

    @if ($watermark ?? null)
        <div class="wm-status {{ $watermark === 'VOID' ? 'wm-void' : '' }}" aria-hidden="true"><span>{{ $watermark }}</span></div>
    @endif

    @if ($confidentialFooter ?? null)
        <div class="wm-foot" aria-hidden="true">{{ $confidentialFooter }}</div>
    @endif
@endif
