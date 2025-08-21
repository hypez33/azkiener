# azkiener – Vercel Bundle (Dashboard Root)

Dieses Paket bündelt deine aktuelle `dashboard/`-App inkl. API-PHPs und einem Vercel-tauglichen `img.php` Proxy.

## Wichtige Punkte
- **vercel.json** aktiviert die PHP Runtime für `api/*.php` und `img.php`.
- **img.php** proxy-cached Bilder in `/tmp/azkiener_img` (ephemeral). Keine serverseitige Skalierung.
- API-Dateien und `lib/` sind übernommen wie bereitgestellt.

## Deploy
1. Project Root in Vercel: `dashboard/`
2. Optional Env Vars für deine API in den Vercel-Einstellungen setzen (z. B. MOBILE_USER/PASSWORD etc.).
3. Frontend-Bilder per Proxy nutzen, z. B.:
   ```html
   <img src="/img.php?src=https%3A%2F%2F...%2Fbild.jpg" alt="...">
   ```
