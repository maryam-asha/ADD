# ADD OS — Form Field Contract (Mobile)

The eight input types the backend can send, what each one stores, and the exact
validation vocabulary the server enforces on submit.

> **One renderer covers both features.** Event registration forms and Service
> request forms are driven by the same enum, the same constraint table and the
> same rule builder on the server. A field object from
> `GET events/{event}/registration-form` and one from
> `GET services/{id}/request-form` are the **same shape** — build the widget
> factory once.

Source of truth: `app/Support/Forms/` — `FieldType.php`, `FieldConstraints.php`,
`FieldRuleBuilder.php`, `ReservedFieldKeys.php`.

---

## 1. The field object

Every field in a form response looks like this, in both features. The server has
already resolved `label`, `placeholder` and each option's `label` down to a
single string in the request locale — you do **not** receive the `{en, ar}`
object.

```jsonc
{
  "id": 14,
  "key": "dietary_needs",        // snake_case, unique per form — your payload key
  "label": "Dietary needs",      // already localized by the server
  "placeholder": "e.g. vegan",   // nullable
  "type": "text",                // one of the 8 below
  "options": [                   // present only for select / checkbox
    { "value": "vegan", "label": "Vegan" }
  ],
  "validation": { "max": 200 },  // per-type vocabulary, see §3
  "is_required": true,
  "is_active": true,
  "order": 3                     // render ascending
}
```

> ⚠️ **Two required flags, OR'd together.** The server treats a field as
> required if `is_required` is true **or** `validation.required` is true. Mirror
> that same OR in the app, or your client-side check will disagree with the
> server on rows that carry only one of them.

---

## 2. The eight types

| `type` | Widget | Send as | Rules the server applies |
| --- | --- | --- | --- |
| `text` | Single-line input | `string` | `string`, min, max, regex |
| `textarea` | Multi-line input | `string` | `string`, min, max, regex |
| `number` | Numeric input | `number` | `numeric`, min, max |
| `date` | Date picker | `"YYYY-MM-DD"` | `date`, min_date, max_date |
| `select` | Single choice | `string` | `string`, `in:` options |
| `checkbox` | Multiple choice | `string[]` | `array`, min, max, `in:` per element |
| `rating` | Star / score picker | `integer` | `integer`, min, max |
| `file` | File picker | multipart file | `file`, `mimes:`, `max:` |

Notes per type:

- **`text` / `textarea`** — identical vocabulary; only the widget differs.
- **`number`** — bounds are *value* bounds and may be fractional. Don't force an
  integer keypad.
- **`date`** — a plain civil date. No time, no timezone.
- **`select`** — send the option's **`value`**, never its label.
- **`checkbox`** — always an array, even for a single tick.
- **`rating`** — whole numbers only, and **always bounded** (see below).
- **`file`** — the one type that cannot go in a JSON body (see §5).

> ℹ️ **Rating defaults to 1–5 when unbounded.** If `validation` carries no
> `min`/`max`, the server still applies `min:1` and `max:5`. An unbounded rating
> widget in the app would let a member submit `0` or `7` and get a 422 back —
> default your picker to 1–5 too.

> ℹ️ **Checkbox `min`/`max` mean item counts.** The same two keys mean
> characters on `text`, a value range on `number`, stars on `rating`, and number
> of boxes ticked on `checkbox`. **Branch on `type` before you interpret them.**

---

## 3. Validation vocabulary, per type

The server rejects any key not listed for that type at *authoring* time, so a
field you receive will only ever carry keys from its own row. Anything absent is
simply unconstrained.

| Type | Key | Shape | Meaning & server rule |
| --- | --- | --- | --- |
| *all types* | `required` | boolean | OR'd with `is_required`. Admin UI doesn't write it; legacy rows carry it. |
| `text`, `textarea` | `min`, `max` | integer | Character count → `min:n` / `max:n` |
| ↳ | `regex` | pattern **name** | One of the seven names in §4. **Never** a raw expression. |
| `number` | `min`, `max` | numeric | Value bound, fractions allowed → `min:n` / `max:n` |
| `date` | `min_date`, `max_date` | `"YYYY-MM-DD"` | Inclusive → `after_or_equal` / `before_or_equal` |
| `checkbox` | `min`, `max` | integer | How many boxes may be ticked (array length) |
| `rating` | `min`, `max` | integer | Score range. Defaults to **1** and **5** when omitted. |
| `file` | `accept` | string | MIME shorthand or extension list. See §5. |
| ↳ | `max_kb` | integer ≥ 1 | Max size in **kilobytes** — not bytes, not MB. |
| `select` | *— none —* | — | The option list **is** the constraint. `validation` will be empty. |

> ⚠️ **Dates deliberately do not reuse `min`/`max`.** They use `min_date` /
> `max_date` precisely so a naive `input.maxLength = validation.max` can never
> pick up the string `"2026-12-31"` and set a maxLength of 10. If your code reads
> `max` generically, it will correctly find nothing on a date field.

---

## 4. The seven named patterns

The `regex` key on `text`/`textarea` carries a **name**, not an expression.
Resolve it to your own client-side check from this fixed list. An unrecognized
name is dropped by the server, so treat it as unconstrained rather than failing
the field.

| Name | Accepts | Client-side equivalent |
| --- | --- | --- |
| `alpha` | Letters and spaces, any script (Arabic included) | `/^[\p{L}\s]+$/u` |
| `alphanumeric` | Letters, digits and spaces, any script | `/^[\p{L}\p{N}\s]+$/u` |
| `digits` | ASCII digits only | `/^[0-9]+$/` |
| `email` | Email address | native email validator |
| `url` | URL | native URL validator |
| `syrian_phone` | `09XXXXXXXX` or `+9639XXXXXXXX` | `/^(\+?963\|0)9[0-9]{8}$/` |
| `no_urls` | Any text containing no `http://` or `https://` | reject if it contains a URL |

---

## 5. Files

`accept` is a shorthand the server expands into an extension whitelist. Map it to
your picker's filter using exactly this table — and note that anything unmapped
means *unconstrained*, not *blocked*.

| `accept` value | Extensions the server will allow |
| --- | --- |
| `image/*` | jpg, jpeg, png, gif, webp, svg |
| `video/*` | mp4, mov, avi, mkv |
| `audio/*` | mp3, wav, ogg, m4a |
| `.pdf` or `application/pdf` | pdf |
| `.doc` or `.docx` | doc, docx |
| `.xls` or `.xlsx` | xls, xlsx |
| `"jpg,png,…"` | the list verbatim — a bare extension list passes through |
| *anything else* | no restriction (server logs a warning and drops the rule) |

A form containing a `file` field must be submitted as `multipart/form-data`, with
each answer still keyed under `fields[...]`. Every other form can go as plain
JSON.

---

## 6. Submitting

All answers nest under a single `fields` object keyed by each field's `key`. The
object is optional — a form with no fields accepts an empty body.

```jsonc
// POST /api/v1/events/{event}/register
// POST /api/v1/services/{id}/requests

{
  "fields": {
    "dietary_needs": "No nuts",        // text / textarea
    "party_size": 3,                   // number
    "arrival_date": "2026-10-02",      // date
    "tshirt_size": "m",                // select — the option value
    "topics": ["ai", "design"],        // checkbox — always an array
    "satisfaction": 4                  // rating — integer
  }
}
```

> 🚫 **Never send `name`, `phone` or `email` as field keys.** These three are
> reserved: identity is taken from the auth token and copied onto the submission
> by the server. Sending one — in any casing — returns a **422** keyed to
> `fields.<key>` rather than being silently ignored.

---

## 7. Error shape

Validation failures come back as a standard Laravel 422. Errors are keyed by the
*dotted path*, so map them back onto your widgets by stripping the `fields.`
prefix.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "fields.dietary_needs": ["Dietary needs must not be greater than 200 characters."],
    "fields.topics.0":      ["The selected Topics is invalid."]
  }
}
```

- `fields.<key>` — an error on the field itself.
- `fields.<key>.<index>` — a **checkbox element** error. Attach it to the whole
  checkbox group, not to an index the member never sees.

Messages arrive already localized and already named after the admin's label
rather than the raw key, so they can be shown to the member verbatim.

---

## 8. Implementation checklist

1. Render fields in ascending `order`; skip anything where `is_active` is false.
2. Treat the field as required when `is_required || validation.required`.
3. Branch on `type` before reading `min`/`max` — the unit changes per type.
4. Default a `rating` to 1–5 when it carries no bounds.
5. Send `checkbox` as an array always; send `select` as the option `value`.
6. Switch to multipart only when the form contains a `file` field.
7. Treat an unknown `type`, `regex` name or `accept` value as **unconstrained and
   still rendered** — the server does the same, so new vocabulary can ship before
   the app updates.
