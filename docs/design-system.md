# Design plan

An agricultural marketplace and training platform for Nigerian farmers — poultry
farmers, feed sellers, equipment dealers, farm workers. Most arrive on a mid-range
Android phone over patchy mobile data.

The platform does not know its own name yet. Every surface that would normally
carry a logo instead carries a **container for a name**, filled at runtime from
settings. Nothing below designs a logo or invents a company name.

## 1. Palette — 5 named values

| Token | Hex | Where it comes from | Where it is used |
|---|---|---|---|
| `zinc-wash` | `#ECEFE8` | whitewashed zinc roofing sheet over a market stall | page ground, table stripes |
| `enamel` | `#0E5138` | bottle-green enamel paint on feed-store shutters and weighing frames | header/footer blocks, primary structure, links |
| `chrome` | `#F5B711` | market umbrellas, chick-starter feed sacks, danfo paint | the one CTA colour, focus ring, tag fills |
| `cockscomb` | `#C22A1B` | a cockerel's comb; oxide print red on sack stencils | danger, price-drop tags, the seam accent |
| `ink` | `#14170F` | printer's ink on kraft — near-black with an olive cast | all body text, every border |

Neutrals are **derived**, not added: a 10-step ramp mixed between `zinc-wash` and
`ink`. No greys enter the palette from outside it.

Area discipline (this is what keeps three high-chroma colours from reading as a
traffic light): `enamel` is structure only, `chrome` never exceeds a button or a
tag, `cockscomb` is small-scale only — danger states and the 2px seam accent.

## 2. Type

**Display — Archivo, pinned to the expanded width** (`wdth 125`, weights 600–800).
A wide industrial grotesque; at heavy weights it reads like paint on a signboard or
two-colour print on a woven sack. Deliberately *not* a high-contrast editorial serif
and not the geometric sans every dashboard reaches for.

**Body — Lexend** (weights 400–700). Chosen for the audience, not the mood. Lexend was
drawn to reduce visual stress and raise reading speed, and it is used in literacy
education — this is a *training* platform whose readers are working in a second
language, on a small screen, often in daylight. Its digits also stay uniform in width,
which matters on a page of prices.

Both faces are shipped with the **slashed zero (`zero`) feature on** wherever a number
is money, weight, a phone number or a code. This app is made of figures a person must
not misread — ₦ prices, 25kg vs 2.5kg, WhatsApp numbers, order references — and `0/O`
is the pair that costs money.

Both are self-hosted, variable, instanced and subset by `scripts/build-fonts.sh` to
exactly the character set this platform needs: Latin, Latin-1, Latin Extended-A, the
Yorùbá/Igbo dot-below vowels (ẹ ọ ṣ ị ụ) with combining tone marks, and the Naira sign
₦ (U+20A6). **One file per face, 64KB for both**, `font-display: swap`, with a system
stack behind them. Unicode-range splitting is deliberately avoided — one request per
face is cheaper than two on a patchy connection.

## 3. Type scale

Base 16px, ratio 1.2, mobile-first; only the display step scales with the viewport.

| Step | Size / line-height | Use |
|---|---|---|
| `2xs` | 11 / 16 | stencil labels, table meta |
| `xs` | 12 / 16 | helper text, badges |
| `sm` | 14 / 20 | dense table cells, form hints |
| `base` | 16 / 24 | body — never smaller for prose |
| `lg` | 18 / 26 | lead paragraphs, card titles |
| `xl` | 20 / 28 | section headings |
| `2xl` | 24 / 30 | page titles (mobile) |
| `3xl` | 30 / 36 | page titles (desktop) |
| `display` | `clamp(2.25rem, 6vw, 4rem)` / 1.02 | hero, certificate name plate |

## 4. Signature element — the stitched sack label

A 50kg feed sack is closed with a chain stitch across the top. That seam is the
whole design language:

- **The label.** A rectangle with a 2px `ink` border and an inset dashed seam
  running inside its top and bottom edge. The brand always lives *inside* this
  panel — header, footer, email header, PDF header, certificate name plate. With a
  logo, the logo sits in the panel. Without one, the panel renders `company_name`
  in Archivo Expanded 800, uppercase, tracked, auto-fitting to one or two lines.
  A short name gets extra tracking; a long name wraps and the panel grows. The
  brand mark is, literally, a container built to hold a name nobody has chosen yet.
- **The seam rule.** The same stitch unwrapped into one horizontal line — the only
  divider used on the site. Section breaks, card tops, email dividers, the line
  under the certificate holder's name.
- **The offset.** Cards and buttons cast a hard 2px `ink` offset instead of a blur —
  the misregistration of cheap two-colour sack printing. **No soft shadows exist in
  this system.** Radius is 2px everywhere except the pill badge.

## 5. Critique — and what changed

Read back cold, the first pass had four tells of a generically "AI-designed" page.

1. **Deep green + gold on light: the default agritech skin.** Kept the green — it is
   honest here and it is the flag — but demoted it. `enamel` is now *structure only*
   (header, footer, rules); the CTA moved to `chrome`, so the page no longer reads as
   a wash of green with a green button on it. The green itself moved from an
   emerald/mint register to dark enamel-paint bottle green.
2. **Rounded-2xl cards with soft shadows.** Deleted outright. Replaced with 2px radius,
   1px `ink` borders and the hard 2px offset. This is the single biggest change and
   the thing that stops the UI looking like every dashboard template.
3. **A fourth "brand blue".** Cut. The first draft had ultramarine as a primary
   alongside green, yellow and red — four hues is a rainbow, not a palette. Five
   values, one of them the ground and one the ink.
4. **Body type picked for vibe, then picked for a claim I had not checked.** The first
   pass reached for a neutral grotesque because it "felt clean". I replaced it with
   Atkinson Hyperlegible Next on a functional argument — misread digits cost money —
   and then actually opened the font: Atkinson Hyperlegible Next has no ₦ and none of
   the dot-below vowels (ẹ ọ ṣ ị ụ). A face that cannot set a Nigerian name or a Naira
   price is disqualified here whatever its legibility research says. Body is now
   **Lexend**, which covers the full set, carries the same kind of reading-proficiency
   argument, and keeps the functional win explicitly through the slashed-zero feature
   rather than through letterform folklore. Every candidate face was checked against
   the real character set before being chosen, not after.

Also checked against the three looks to avoid:
- *Cream + high-contrast serif + terracotta* — ground is cool zinc, not cream; no serif
  anywhere; `cockscomb` is a scarlet oxide red, not a muted terracotta, and is capped
  at small-scale use.
- *Near-black with one acid accent* — the page is light, and there are three chromatic
  colours doing distinct structural jobs.
- *Broadsheet hairline rules* — every rule in the system is a 2px dashed seam, never a
  hairline, and the layout is blocked and filled rather than ruled.

## 6. Constraints the layouts must hold

- Mobile-first, correct down to **360px**.
- A **short name**, a **long name** and a **missing logo** all render correctly in the
  label panel, in the header, footer, email header, PDF header and certificate.
- Visible keyboard focus: 2px `chrome` ring with a 2px `ink` outer, never removed.
- `prefers-reduced-motion` honoured — transitions collapse to 0ms.
- All content images `loading="lazy" decoding="async"` with explicit dimensions.
- Colour is never the only signal — every state carries a label or an icon too.
