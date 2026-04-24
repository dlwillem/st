<?php
/**
 * FAQ — veelgestelde vragen over de DKG SelectieTool.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/users.php';
require_login();

$pageTitle  = 'FAQ';
$currentNav = 'faq';

$faqGroups = [
    'Algemeen' => [
        [
            'q' => 'Waar is de DKG SelectieTool voor bedoeld?',
            'a' => 'De tool ondersteunt het volledige proces van softwareselectie bij DKG: van het vastleggen van requirements, via uitvraag bij leveranciers, tot automatisch en handmatig scoren en een eindrapportage met rangschikking.',
        ],
        [
            'q' => 'Voor wie is de tool?',
            'a' => 'Interne DKG-rollen: architecten beheren de hele tool en alle trajecten, business owners stellen binnen hun eigen traject(en) requirements en leveranciers op en bepalen de weging, business analisten onderhouden requirements en scoren binnen toegewezen trajecten, en key-users krijgen read-only inzage plus de mogelijkheid om scores in te vullen. Leveranciers werken extern via een ingevuld Excel-bestand — zij hebben geen eigen login.',
        ],
        [
            'q' => 'Welke rollen zijn er en wat mogen ze?',
            'a' => '<strong>Architect</strong>: super-user — volledige rechten op alle trajecten, inclusief gebruikersbeheer, Structuur stamdata, audit-trail, weging en scoring starten. <strong>Business owner</strong>: beheert — binnen de trajecten waaraan hij is gekoppeld — requirements, leveranciers en weging, en bekijkt rapportage; mag zelf scores invullen, maar start geen scoring-rondes en koppelt geen collega\'s. <strong>Business analist</strong>: binnen toegewezen trajecten requirements onderhouden, rapportage bekijken en scores invullen. <strong>Key-user</strong>: inzage (read-only) in toegewezen trajecten en scores invullen via een uitnodigingslink. De volledige autorisatiematrix staat live in Instellingen en wordt rechtstreeks uit de code gegenereerd.',
        ],
        [
            'q' => 'Wat is een "traject"?',
            'a' => 'Een traject is één selectie-project voor een specifieke applicatie of applicatiegroep. Een traject bundelt: requirements, deelnemende leveranciers, deelnemers (interne beoordelaars) en scoring-rondes.',
        ],
        [
            'q' => 'Wat is een "scope" en welke zijn er?',
            'a' => 'Een scope is een hoofdcategorie requirements: FUNC (functioneel), NFR (non-functioneel), VEND (leverancier), LIC (licentie), SUP (support) en DEMO (demo-beoordeling). Per leverancier wordt per scope een eigen scoring-ronde bijgehouden.',
        ],
    ],
    'Requirements' => [
        [
            'q' => 'Hoe voeg ik requirements toe?',
            'a' => 'Onder Requirements of via het detail van een traject. Per requirement kies je categorie + thema (subcategorie), type (Eis / Wens / Knock-out) en vul je titel en omschrijving in. Codes (FR-001, NFR-001, …) worden automatisch gegenereerd. Dit mogen architecten, business owners en business analisten — elk binnen de trajecten waaraan zij gekoppeld zijn (architecten zien alle trajecten).',
        ],
        [
            'q' => 'Wat is het verschil tussen Eis, Wens en Knock-out?',
            'a' => 'Eis (Must): requirement moet worden ingevuld. Wens (Should): gewenst maar niet blokkerend. Knock-out: een "Nee" zonder toelichting leidt tot automatische flag "Onder review" — de leverancier kan alsnog afgewezen worden op basis van beoordeling.',
        ],
        [
            'q' => 'Kan ik requirements importeren uit Excel?',
            'a' => 'Ja, rechtstreeks op de Requirements-pagina zelf via de knop "Uploaden" (naast "Exporteren"). Het formaat moet overeenkomen met de export: per scope een tabblad (FUNC/NFR/VEND/LIC/SUP) met kolommen Nr, Domein, Titel, Omschrijving en MoSCoW. De upload doet strict all-or-nothing: alle regels slagen, of niets wordt weggeschreven. Validatie: hoofdcategorie + thema moeten bestaan in het traject, MoSCoW moet een geldige waarde zijn (MUST/SHOULD/KNOCK-OUT), en bij fouten krijg je tab + rijnummer terug. Zodra er al scores zijn ingevoerd is de upload gelocked — je ziet dan een 🔒 en de knop blokkeert om te voorkomen dat requirements onder beoordelaars wegveranderen.',
        ],
        [
            'q' => 'Wat zijn thema\'s?',
            'a' => 'Thema\'s zijn de subcategorieën onder een scope — bijvoorbeeld "Autorisatie" onder NFR of "Facturatie" onder FUNC. Ze helpen de requirementset overzichtelijk op te delen.',
        ],
    ],
    'Leveranciers & upload' => [
        [
            'q' => 'Hoe nodig ik een leverancier uit?',
            'a' => 'Maak een leverancier aan onder het traject, download via de knop "Download" het Excel-template voor díe leverancier en stuur dat op. Het bestand bevat alle requirements, gesplitst per scope-tab.',
        ],
        [
            'q' => 'Wat moet de leverancier invullen?',
            'a' => 'Per requirement: kolom "Standaard" met verplicht één van de waarden <strong>Ja</strong>, <strong>Nee</strong> of <strong>Deels</strong>, en optioneel een Toelichting. Alleen de Standaard-kolom is verplicht; de Toelichting mag leeg blijven. Lege of ongeldige Standaard-waarden worden bij upload geweigerd met regel + tab in de foutmelding.',
        ],
        [
            'q' => 'Hoe upload ik een ingevuld bestand?',
            'a' => 'Op het tabblad Leveranciers van het traject staat een "Upload"-knop per leverancier. Na upload zie je eerst een preview met alle classificaties; pas na "Toepassen" worden auto-scores écht weggeschreven.',
        ],
        [
            'q' => 'Kan ik een upload vervangen of verwijderen?',
            'a' => 'Ja, zolang er nog geen handmatige scoring is begonnen. Zodra een beoordelaar scores heeft ingevoerd, is de upload gelocked — je ziet dan een 🔒 in de UI.',
        ],
        [
            'q' => 'Wat gebeurt er als ik een upload verwijder na een KO-flag?',
            'a' => 'De status "Onder review" wordt teruggezet naar "Actief", `ko_failed_reason` wordt gewist en alle auto-scores + antwoorden verdwijnen. Een handmatig gezette "Afgewezen"-status op basis van KO wordt ook teruggezet.',
        ],
    ],
    'Auto-scoring' => [
        [
            'q' => 'Wat is auto-scoring?',
            'a' => 'Elke antwoordregel van de leverancier wordt automatisch geclassificeerd zodat wij alleen regels hoeven te beoordelen die nuance verdienen. Dit versnelt het scoren significant bij grote requirementsets.',
        ],
        [
            'q' => 'Welke regels hanteert de auto-scoring?',
            'a' => 'Ja zonder toelichting → hoogste score (5). Nee zonder toelichting → laagste score (1). Ja/Nee mét toelichting → handmatig beoordelen. Deels → altijd handmatig. N.v.t. → niet gescoord.',
        ],
        [
            'q' => 'Wat is een "toelichting" precies?',
            'a' => 'Toelichting is "aanwezig" als het toelichting-veld ≥ 5 tekens bevat óf als er een bewijs-URL (evidence_url) is opgegeven. Kortere tekst geldt als geen toelichting.',
        ],
        [
            'q' => 'Wat gebeurt er bij een Knock-out?',
            'a' => 'KO met "Nee" zonder toelichting → leverancier-status wordt "Onder review" (waarschuwings-driehoekje, amber badge) en de reden wordt opgeslagen. De beoordelaar kan vervolgens kiezen om af te wijzen of een uitzondering te maken.',
        ],
        [
            'q' => 'Kan ik auto-scores overschrijven?',
            'a' => 'Ja. Op het scoring-scherm zijn auto-scores zichtbaar als voorstel; handmatige invoer overschrijft ze (met source=manual). Auto-scores zijn vastgelegd met source=auto en blijven traceerbaar.',
        ],
    ],
    'Scoring & rapportage' => [
        [
            'q' => 'Hoe werkt het scoring-scherm?',
            'a' => 'Het scorescherm toont drie secties: (1) KO-regels bovenaan — altijd eerst bekijken, (2) handmatig te beoordelen, (3) auto-gescoord (ingeklapt). Je geeft een score 1–5 met optionele notitie.',
        ],
        [
            'q' => 'Waarom staat een ronde op "Concept"?',
            'a' => 'Rondes beginnen in "Concept" na de upload-commit. Je promoot naar "Open" zodra beoordelaars mogen scoren. Een ronde op "Gesloten" telt mee in de Rapportage-tegel op de home en in eindrangschikkingen.',
        ],
        [
            'q' => 'Hoe wordt de eindscore berekend?',
            'a' => 'De eindscore is een gewogen gemiddelde over drie niveaus: (1) per requirement wordt het gemiddelde van alle beoordelaars genomen en binnen een subcategorie gewogen met Must 2× en Should 1×; (2) subcategoriescores worden gewogen opgeteld tot een hoofdcategoriescore volgens de ingestelde sub-gewichten (samen 100%); (3) hoofdcategoriescores worden gewogen tot de eindscore volgens de traject-weging, waarbij de leveranciers-demo een configureerbaar gewichtspercentage heeft. KO-requirements vallen buiten de berekening maar leveren een ⚠️-markering op bij een gemiddelde ≤ 2. Niet-ingevulde scores tellen niet mee: een leeggelaten beoordelaar wordt overgeslagen in het gemiddelde van dat requirement, en een requirement zonder enkele score wordt overgeslagen binnen zijn subcategorie. Volledige uitleg met voorbeeldberekeningen staat op de Rapportage-pagina.',
        ],
        [
            'q' => 'Wat gebeurt er als meerdere beoordelaars dezelfde ronde scoren?',
            'a' => 'Elke beoordelaar krijgt zijn eigen score-regel (via deelnemer_id). De rapportage gebruikt het gemiddelde over alle aangewezen deelnemers per requirement.',
        ],
    ],
    'Validaties & checks' => [
        [
            'q' => 'Welke validaties zitten op de leverancier-upload (ingevuld Excel-bestand)?',
            'a' => 'Alleen <code>.xlsx</code>; de requirement-codes in het bestand moeten exact bestaan in het traject (geen onbekende of verwijderde codes); lege "Standaard"-waarden worden geweigerd met rij + tab-naam; ongeldige waarden (alles behalve Ja / Nee / Deels) geven een fout. Toelichting is optioneel. De upload draait eerst in een preview-fase (classificatie per regel: auto max / auto min / handmatig / KO) en wordt pas definitief na "Toepassen".',
        ],
        [
            'q' => 'Welke validaties zitten op de interne requirements-upload?',
            'a' => 'Alleen <code>.xlsx</code> in het formaat van de export (per scope een tabblad met Nr / Domein / Titel / Omschrijving / MoSCoW); hoofdcategorie en thema (Domein) moeten bestaan in het traject — onbekende waarden worden geweigerd; MoSCoW moet geldig zijn (MUST / SHOULD / KNOCK-OUT); lege verplichte velden geven een fout met tab + rijnummer. De upload is <strong>strict all-or-nothing</strong>: één fout rolt alles terug. Als er al scores zijn ingevoerd is de upload gelocked om requirements niet onder beoordelaars te muteren.',
        ],
        [
            'q' => 'Welke checks bij scoring?',
            'a' => 'Scores moeten 1–5 zijn; je kunt niet scoren in een gesloten ronde; KO-flagging gebeurt automatisch op basis van antwoord + toelichting; rondes worden per scope aangemaakt, ook als er alleen handmatige regels zijn.',
        ],
        [
            'q' => 'Hoe wordt alles gelogd?',
            'a' => 'Relevante acties (upload, commit, KO-flag, score-wijziging, leverancier-status) gaan naar de audit-trail met actor, tijdstip en beknopte context. Admin/architect kan dit bekijken via Audit Trail.',
        ],
        [
            'q' => 'Hoe wordt CSRF / sessiebeveiliging afgehandeld?',
            'a' => 'Alle POST-formulieren bevatten een CSRF-token (verplicht gevalideerd). Login is verplicht voor elke pagina; rol-gebaseerde toegang wordt per actie afgedwongen.',
        ],
        [
            'q' => 'Waar staan de geüploade Excel-bestanden?',
            'a' => 'In <code>uploads/leverancier_excel/</code> binnen de app-root, met een .htaccess die directe web-toegang blokkeert. De bestandsnaam bevat leverancier-id + timestamp.',
        ],
    ],
    'Rapportage — dieper' => [
        [
            'q' => 'Wat vind ik op de Rapportage-pagina?',
            'a' => 'Een per-traject overzicht van alle leveranciers met hun scores per scope (FUNC, NFR, VEND, LIC, SUP, DEMO), gewogen eindscore, knock-out-status en een rangschikking. Je kunt inzoomen op een leverancier voor per-requirement detail.',
        ],
        [
            'q' => 'Welke wegingen worden gebruikt?',
            'a' => 'Requirements wegen mee op basis van MoSCoW: Eisen (Must) wegen zwaarder dan Wensen (Should). Knock-outs wegen niet als gewone score mee — ze zijn hard blokkerend of niet. Per scope wordt een gewogen gemiddelde berekend.',
        ],
        [
            'q' => 'Hoe wordt de eindscore precies berekend?',
            'a' => 'De eindscore van een leverancier is een gewogen gemiddelde over drie niveaus.

<strong>Niveau 1 — van scores naar subcategoriescore</strong><br>
Beoordelaars scoren elke requirement op 1–5. Die scores worden eerst gemiddeld over alle beoordelaars. Daarna wordt een gewogen gemiddelde berekend waarbij <strong>Must (Eis) 2× telt en Should (Wens) 1×</strong>.

<table class="faq-calc" style="margin:10px 0;border-collapse:collapse;font-size:13px;">
<thead><tr style="background:#f3f4f6;">
  <th style="padding:6px 10px;text-align:left;border:1px solid #e5e7eb;">Requirement</th>
  <th style="padding:6px 10px;text-align:left;border:1px solid #e5e7eb;">MoSCoW</th>
  <th style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">Gem. score</th>
  <th style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">Gewicht</th>
</tr></thead>
<tbody>
<tr><td style="padding:6px 10px;border:1px solid #e5e7eb;">HLR-02 Configureerbare variabelen</td><td style="padding:6px 10px;border:1px solid #e5e7eb;">Must</td><td style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">3.7</td><td style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">2</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e5e7eb;">HLR-03 Component vervanging</td><td style="padding:6px 10px;border:1px solid #e5e7eb;">Must</td><td style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">4.3</td><td style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">2</td></tr>
<tr><td style="padding:6px 10px;border:1px solid #e5e7eb;">HLR-05 Catalogusimport</td><td style="padding:6px 10px;border:1px solid #e5e7eb;">Should</td><td style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">3.0</td><td style="padding:6px 10px;text-align:right;border:1px solid #e5e7eb;">1</td></tr>
</tbody></table>

<pre style="background:#0d1117;color:#e5e7eb;padding:12px 14px;border-radius:8px;font-size:12.5px;line-height:1.5;overflow:auto;">(3.7×2) + (4.3×2) + (3.0×1)  =  19.0  ÷  5  =  <strong style="color:#67e8f9;">3.80</strong></pre>

<strong>Niveau 2 — van subcategoriescore naar hoofdcategoriescore</strong><br>
<pre style="background:#0d1117;color:#e5e7eb;padding:12px 14px;border-radius:8px;font-size:12.5px;line-height:1.5;overflow:auto;">L-9.1  score 4.2  × 30%  =  1.26
L-9.2  score 3.8  × 20%  =  0.76
L-9.3  score 3.1  × 50%  =  1.55
                              ────
                              <strong style="color:#67e8f9;">3.57</strong></pre>

<strong>Niveau 3 — van hoofdcategoriescore naar eindscore</strong><br>
<pre style="background:#0d1117;color:#e5e7eb;padding:12px 14px;border-radius:8px;font-size:12.5px;line-height:1.5;overflow:auto;">Functioneel       3.57  × 35%  =  1.25
Non-functioneel   4.10  × 15%  =  0.62
Leverancier       3.80  × 20%  =  0.76
Licentiemodel     3.20  × 10%  =  0.32
Support           3.60  × 20%  =  0.72
                                  ────
Eindscore                         <strong style="color:#67e8f9;">3.67</strong>  →  67 / 100</pre>

<strong>KO-requirements</strong> vallen buiten deze berekening. Scoort een leverancier gemiddeld ≤ 2 op een KO-requirement, dan verschijnt een ⚠️ naast de eindscore — ongeacht hoe hoog die is.',
        ],
        [
            'q' => 'Hoe werkt het DEMO-aandeel?',
            'a' => 'DEMO slaat op de <strong>demo die de leverancier bij DKG geeft</strong> (live presentatie / product-demo) — niet op een demo van de tool zelf. Per traject stel je een DEMO-weight in (percentage). De eindscore is dan een gewogen combinatie: (100 − DEMO%) voor de requirement-scores en DEMO% voor de beoordeling van de leveranciers-demo. Standaard staat DEMO op 0, zodat het pas meeweegt als je bewust een percentage kiest.',
        ],
        [
            'q' => 'Welke scores worden meegenomen in de rapportage?',
            'a' => 'Alleen scores uit rondes met status "Open" of "Gesloten" tellen mee. Concept-rondes zijn werk-in-uitvoering en worden genegeerd tot ze gepromoveerd zijn. Auto-scores tellen mee tenzij er een handmatige score over heen is gezet.',
        ],
        [
            'q' => 'Hoe gaat de rapportage om met leveranciers op "Onder review"?',
            'a' => 'Ze blijven in de rangschikking staan met hun huidige scores, inclusief een visuele markering (⚠ amber badge) en de KO-reden in de tooltip. Pas wanneer je ze handmatig op "Afgewezen" zet worden ze uit de eindrangschikking gefilterd.',
        ],
        [
            'q' => 'Kan ik de rapportage exporteren?',
            'a' => 'Op dit moment niet. De rapportage is alleen on-screen beschikbaar (ranking + drill-down per leverancier). Een Excel- of PDF-export per traject staat op de roadmap; voorlopig kun je de browser-print gebruiken of een screenshot maken als bijlage bij selectie-documentatie.',
        ],
    ],
    'Structuur stamdata' => [
        [
            'q' => 'Wat is de Structuur stamdata?',
            'a' => 'Structuur stamdata is de centrale plek voor stamdata: hoofdcategorieën, applicatiesoorten (FUNC-templates), thema-templates voor NFR/VEND/LIC/SUP, en de mastersets van requirements. Vanuit hier worden trajecten gevoed.',
        ],
        [
            'q' => 'Wat zijn applicatiesoorten?',
            'a' => 'Applicatiesoorten zijn herbruikbare functionele templates (bijv. "ERP", "CRM", "WMS"). Bij het aanmaken van een traject kies je één of meerdere applicatiesoorten — hun bijbehorende FUNC-thema\'s en requirements worden dan als startpunt naar het traject gekopieerd.',
        ],
        [
            'q' => 'Waar komen de FUNC-requirements vandaan?',
            'a' => 'De functionele requirements per applicatiesoort zijn <strong>rechtstreeks afgeleid van onze doelarchitectuur in Blue Dolphin</strong>: elke applicatieservice uit Blue Dolphin vormt de basis voor één of meer FUNC-requirements. Zo blijft de requirementset synchroon met onze enterprise-architectuur.',
        ],
        [
            'q' => 'Wat als Blue Dolphin wijzigt?',
            'a' => 'Updates worden periodiek gesynchroniseerd met de Structuur stamdata (handmatig proces — nog niet realtime). Lopende trajecten blijven werken op de op dat moment gekopieerde requirementset, zodat wijzigingen in de doelarchitectuur een lopend selectieproces niet destabiliseren.',
        ],
        [
            'q' => 'Hoe werken NFR/VEND/LIC/SUP-templates?',
            'a' => 'Voor deze scopes zijn er platte thema-templates in de Structuur stamdata. Bij het samenstellen van een traject kies je per scope welke thema\'s relevant zijn; alleen geselecteerde thema\'s + hun requirements komen in het traject terecht.',
        ],
        [
            'q' => 'Kan ik stamdata vanuit een traject terug-syncen?',
            'a' => 'Nee. Traject-requirements zijn een kopie — wijzigingen in een traject beïnvloeden de mastersets niet. Dat voorkomt dat project-specifieke aanpassingen andere lopende trajecten raken. Verbeteringen moet je bewust in de Structuur stamdata aanbrengen.',
        ],
        [
            'q' => 'Wie mag de Structuur stamdata beheren?',
            'a' => 'Alleen admin en architect. Key-users kunnen wel requirements binnen hun traject aanpassen, maar niet de mastersets of applicatiesoorten. Management heeft geen toegang tot de Structuur stamdata.',
        ],
    ],
    'Technisch' => [
        [
            'q' => 'Op welke techniek draait de tool?',
            'a' => 'PHP 8.2+ (MySQL via PDO), vanilla JS frontend met Nunito Sans typografie, PHPSpreadsheet voor Excel-I/O, Apache als webserver. Geen frontend-framework — alles server-rendered met kleine JS-enhancements.',
        ],
        [
            'q' => 'Is de broncode door AI geschreven?',
            'a' => 'Ja, de applicatie is ontwikkeld in nauwe samenwerking met <strong>Claude AI (Anthropic)</strong>. Ontwerpbeslissingen en requirements komen van het DKG-team; implementatie, iteratie en refactoring gebeurden pair-programming-stijl met Claude.',
        ],
    ],
];

$bodyRenderer = function () use ($faqGroups) {
?>
  <div class="page-header">
    <div>
      <div class="ph-title">Veelgestelde vragen</div>
      <div class="ph-sub">Alles over requirements, uploads, auto-scoring en rapportage — in één overzicht.</div>
    </div>
  </div>

  <div class="sbar" style="margin-bottom:16px;">
    <div class="sinp-w" style="flex:1;max-width:420px;">
      <?= icon('search', 14) ?>
      <input type="text" class="sinp" id="faq-q" placeholder="Zoek in FAQ…" autofocus>
    </div>
  </div>

  <?php foreach ($faqGroups as $group => $items): ?>
    <div class="sc faq-group" style="margin-bottom:16px;">
      <div class="sc-head"><div class="sc-title"><?= h($group) ?></div></div>
      <div class="sc-body" style="padding:0;">
        <?php foreach ($items as $i => $qa): ?>
          <details class="faq-item" style="border-top:1px solid var(--border, #e5e7eb);padding:14px 20px;">
            <summary style="cursor:pointer;font-weight:600;list-style:none;display:flex;gap:10px;align-items:flex-start;">
              <span style="color:#0891b2;flex-shrink:0;">Q.</span>
              <span class="faq-q-text"><?= h($qa['q']) ?></span>
            </summary>
            <div class="faq-a" style="margin-top:10px;padding-left:26px;color:#374151;line-height:1.55;">
              <?= $qa['a'] /* controlled content */ ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <style>
    .faq-item[open] summary { color:#0891b2; }
    .faq-item summary::-webkit-details-marker { display:none; }
    .faq-item.is-hidden { display:none; }
    .faq-group.is-empty { display:none; }
  </style>
  <script>
    (function(){
      var input = document.getElementById('faq-q');
      if (!input) return;
      input.addEventListener('input', function(){
        var q = input.value.trim().toLowerCase();
        document.querySelectorAll('.faq-group').forEach(function(g){
          var any = false;
          g.querySelectorAll('.faq-item').forEach(function(it){
            var txt = it.textContent.toLowerCase();
            var match = !q || txt.indexOf(q) !== -1;
            it.classList.toggle('is-hidden', !match);
            if (match) any = true;
            if (q && match) it.setAttribute('open',''); else if (!q) it.removeAttribute('open');
          });
          g.classList.toggle('is-empty', !any);
        });
      });
    })();
  </script>
<?php };

require __DIR__ . '/../templates/layout.php';
