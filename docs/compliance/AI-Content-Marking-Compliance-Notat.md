# AI Content Marking - Compliance Notat

## EU AI Act Article 50 - Opfyldelse

### 1. Overordnet tilgang
Eira MultiChat anvender en flerlags mærkningsstrategi for at opfylde kravet om "as far as technically feasible".

### 2. Implementerede lag

#### 2.1 Brugergrænseflade
- Alle AI-svar vises med tydeligt badge: "🤖 AI-genereret"
- Request ID, model og timestamp vises i UI
- Brugeren kan altid se at indholdet er AI-genereret

#### 2.2 Copy-paste beskyttelse
- Når brugeren kopierer AI-indhold, tilføjes prefix: `[AI-GENERERET - Eira AI]`
- HTML-clipboard indeholder metadata (`data-ai-generated`, `data-request-id`)
- Copy-events logged til audit (anonymiseret)

#### 2.3 Eksport til dokumenter
- **DOCX:** Synlig mærkning i header + metadata (Creator, Custom properties)
- **PDF:** Synlig mærkning i footer + metadata
- **Filnavn:** `Eira_AI_[dato]_[request_id].ext`

#### 2.4 Audit og governance
- Alle AI-svar logged i `ai_requests` med:
  - `request_id`, `provider`, `model`, `watermark_type`
  - `provider_request_id` (Claude/Gemini's eget ID)
  - `timestamp`, `policy_version`
- Intern request-ID lookup til verifikation
- 3 års retention jf. GDPR + AI Act

#### 2.5 Provider-valg
- **Claude (Anthropic):** SynthID watermark
- **Gemini (Google):** SynthID-Text watermark
- Begge provideres watermark-support logged

### 3. Begrænsninger (dokumenterede og accepterede)

| Risiko | Impact | Mitigation |
|--------|--------|------------|
| Bruger omskriver tekst | Watermark forsvinder | Originalt svar i audit; semantisk tjek ved audit-behov |
| Bruger kopierer uden for systemet | Disclaimer mistes | Copy-events logged; synlig mærkning i UI |
| Dokument eksporteres til plain text | Metadata mistes | Synlig mærkning i indholdet |
| Tredjepart deler dokument | Verifikation svær | Request ID til intern lookup |

### 4. "As far as technically feasible"

Vi vurderer at vores 4-lags strategi repræsenterer **rimelig teknisk indsats**:

| Lag | Effektivitet | Evidens |
|-----|--------------|---------|
| UI mærkning | 100% i vores system | Screenshots |
| Copy beskyttelse | Effektiv ved standard copy-paste | Copy events logged |
| Dokument metadata | Effektiv i eksporterede filer | Testede filer |
| Audit trail | 100% i vores system | Database forespørgsler |
| Provider watermark | Providernes eget lag | Logget i ai_requests |

**Yderligere tiltag vurderet og fravalgt:**
- **C2PA:** For tungt til pilot, evalueres i fase 2
- **Offentlig verifikation:** Ikke efterspurgt, kan tilføjes
- **Semantisk fingerprinting:** For komplekst, ikke nødvendigt

### 5. Anbefaling til DPO
Vi anbefaler at denne strategi godkendes som "as far as technically feasible" for piloten. Yderligere tiltag kan implementeres i fase 2-3 baseret på erfaringer og kundebehov.

### 6. Opfølgning
- **DPO-review:** Hvert kvartal
- **Teknisk review:** Hver sprint
- **Opdatering af policy_version:** Ved ændringer

---

**Dokument ID:** COMP-2026-08-20-001  
**Version:** 1.0  
**Godkendt af:** [DPO/ledelse]  
**Dato:** 2026-08-20
