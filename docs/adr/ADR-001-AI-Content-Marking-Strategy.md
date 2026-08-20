# ADR-001: AI Content Marking Strategy

## Status
Accepted (2026-08-20)

## Context
EU AI Act Article 50 træder i kraft 2. august 2026 og kræver at:

- Providers af generative AI-systemer mærker syntetisk indhold i machine-readable format
- Deployers informerer brugere om AI-interaktion
- Markering er "as far as technically feasible"

Vi er i pilotfase med Eira MultiChat (2-3 udviklere, 12-16 uger). Vi skal balancere compliance med ressourcebegrænsninger.

## Decision
Vi implementerer 4-lags mærkningsstrategi:

### Layer 1: UI (Dag 1)
- Visuelt badge på alle AI-svar: "🤖 AI-genereret"
- Request ID synlig og kopierbar
- Timestamp og model-information

### Layer 2: Copy (Dag 1)
- Disclaimer prefix ved copy-paste: `[AI-GENERERET - Eira AI]`
- HTML metadata i clipboard (data-ai-generated, data-request-id)
- Copy-events logged til audit

### Layer 3: Export (Dag 2)
- DOCX: Synlig mærkning i header + metadata (Creator, Custom properties)
- PDF: Synlig mærkning i footer + metadata
- Filnavn: `Eira_AI_[dato]_[request_id].ext`

### Layer 4: Audit (Dag 3)
- Alle AI-svar logged med request_id, provider, model, watermark_type
- Intern request-ID lookup til verifikation
- Tenant-scoping via organization_id

### Provider Strategy
- Foretræk Claude/Gemini (understøtter SynthID watermark)
- Provider wrapper er generisk til fremtidige udvidelser
- Watermark-support logged i ai_requests tabellen

## Alternatives Considered
| Alternative | Vurdering | Beslutning |
|-------------|-----------|------------|
| C2PA Content Credentials | Kræver kryptografisk signering, komplekst i PHP | Drop i pilot, evaluer i fase 2 |
| Semantisk fingerprinting | Beviser kun lighed, ikke oprindelse. False positives. | Drop i pilot |
| Offentlig verifikationsportal | Tidskrævende, ikke efterspurgt | Fase 3-4 |
| Sprog-specifik watermark | Providers klarer selv sprog | Drop |

## Consequences
**Positive:**
- Artikel 50 krav opfyldt via flerlagskonfiguration
- Full audit trail af alle AI-svar
- Brugeren ser tydeligt AI-indhold
- Copy-paste inkluderer disclaimer
- Evidens for "as far as technically feasible"

**Negative:**
- Copy udenfor systemet mister disclaimer (men copy events logged)
- Parafrasering kan degrade watermark (men originalt svar i audit)
- Metadata forsvinder ved ren tekstkopi (men synlig mærkning i content)

## Compliance Documentation
- Governance-rapport: Sektion 4.3 "AI Content Marking"
- DPO-review: Hvert kvartal
- Audit trail: 3 års retention

## Related ADRs
- ADR-002: AI Provider Gateway
- ADR-008: Audit Logging Strategy

## Approved By
- Teknisk lead: [Navn]
- DPO: [Navn]
- Dato: 2026-08-20
