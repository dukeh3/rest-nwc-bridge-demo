# rest-nwc-bridge-demo

Demos for [rest-nwc-bridge](https://github.com/AiCaramba/rest-nwc-bridge) — an HTTP-to-NWC gateway that translates REST API calls to Nostr Wallet Connect (NIP-47).

## Prerequisites

- PHP 8.1+ with `curl` extension
- A running [rest-nwc-bridge](https://github.com/AiCaramba/rest-nwc-bridge) instance

## Setup

```bash
cp .env.example .env
```

Edit `.env` with the URL of your running bridge:

```
BRIDGE_URL=http://localhost:8080
```

## Demos

### CLI: Basic info and balance

```bash
php cli/demo.php
```

Queries the bridge for `GET /info` and `GET /balance`.

### Web: Send and receive UI

```bash
php -S localhost:18080 -t web/
```

Open http://localhost:18080. The web app provides:

- **Wallet balance** displayed at the top
- **Receive** — creates a bolt11 invoice via `POST /invoice`, then polls `GET /invoice/:payment_hash` until payment is detected
- **Send** — pays a bolt11 invoice via `POST /pay`

## How It Works

Unlike the [nostr-php-nwc-demo](https://github.com/dukeh3/nostr-php-nwc-demo) which connects to the wallet directly over Nostr relays, this demo talks to a REST API bridge:

```
PHP demo  ──HTTP──>  rest-nwc-bridge  ──NWC/Nostr──>  Lightning wallet
```

No Nostr keys, no WebSocket connections, no NIP-44 encryption — just plain HTTP/JSON calls to the bridge.

## License

Copyright (C) 2025

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.
