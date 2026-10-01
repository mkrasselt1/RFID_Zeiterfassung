{{--
    Spendenhinweis in der Seitenleiste.

    Bewusst zurückhaltend und nur für Verwaltende: Mitarbeitende stempeln hier
    ihre Arbeitszeit, die sollen nicht um Geld gebeten werden. Über
    *Einstellungen → Allgemein* lässt sich der Hinweis ganz abschalten — eine
    Lösung, die ein Betrieb nicht stummschalten kann, setzt er nicht ein.
--}}
@if((auth()->user()?->canManagePeople() ?? false) && \App\Models\Setting::get('show_donation_link', true))
<div style="padding:12px 16px;border-top:1px solid rgba(127,127,127,.2);font-size:11px;line-height:1.45;">
    <div style="opacity:.65;">Freie Software, kostenlos nutzbar.</div>
    <a href="https://paypal.me/krasm" target="_blank" rel="noopener noreferrer"
       style="display:inline-block;margin-top:4px;font-weight:600;color:rgb(217 119 6);text-decoration:none;">
        Projekt unterstützen &rarr;
    </a>
</div>
@endif
