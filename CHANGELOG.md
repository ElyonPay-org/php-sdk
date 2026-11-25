# Changelog

## 1.1.0

- **API key authentication**: `new Client(['api_key' => 'pk_live_egt_...'])`. No login, no
  token to cache; a `401` is not retried. The email + password login keeps working.
- **Webhooks**: `Webhook::parse()` reads a notification (event, delivery id, attempt,
  transaction uuid). The body is unsigned: always confirm with `getTransaction()`.
- `createPaymentLink()` accepts `customer.phone` in place of `msisdn`; `webhook`,
  `external_id`, `customer`, `onbehalfof` and `fees` are passed through.
- API errors shaped `{"message": ...}` (string or list) now surface their message.
- `timeout` option for the default cURL client.
- `listTransactions()` is deprecated: the API serves no list to integrations.

## 1.0.1

- `PENDING` (and its future name `WAITING_FOR_DELIVERY`) is a **paid** state.

## 1.0.0

- First release: payment link, transaction lookup, status mapping.
