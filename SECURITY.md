# Security policy

## Een kwetsbaarheid melden

Heb je een beveiligingsprobleem gevonden in deze applicatie? Bedankt dat je
de moeite neemt het te melden.

**Meld het privé**, niet via een GitHub issue of pull request:

- **E-mail:** willem@digilance-consulting.nl
- Of via GitHub's *Private vulnerability reporting*: ga naar het
  [Security-tabblad](../../security/advisories/new) van deze repo en maak
  een advisory aan.

Geef in je melding zo veel mogelijk context:

- Beschrijving van de kwetsbaarheid en de mogelijke impact.
- Stappen om het probleem te reproduceren (URL's, payloads, screenshots).
- De versie / commit-hash waartegen je hebt getest.
- Eventuele suggesties voor mitigatie.

## Wat je mag verwachten

- **Bevestiging van ontvangst** binnen 5 werkdagen.
- **Eerste inhoudelijke reactie** binnen 14 dagen.
- Coördinatie van een fix en disclosure-tijdlijn als de melding bevestigd
  wordt.
- Credit in de release-notes (tenzij je liever anoniem blijft).

## Scope

In scope zijn kwetsbaarheden in de code in deze repository. Out of scope:

- Issues in third-party dependencies — meld die direct bij de upstream
  maintainer (zie `composer.json`). Dependabot houdt versies hier bij.
- Issues op een specifieke deployment van deze software (configuratie,
  hosting). Neem contact op met de operator van die instantie.
- Social engineering, fysieke aanvallen, of brute-force attacks tegen
  productie-instanties.

## Ondersteunde versies

Alleen de `main`-branch ontvangt actieve security-fixes. Eerdere tags
worden niet onderhouden.

## Coordinated disclosure

Wij vragen je om gevonden kwetsbaarheden niet publiek te maken voordat
een fix beschikbaar is, of tot 90 dagen na je melding (welke eerder is).
