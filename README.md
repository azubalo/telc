# PrintOnNow blog otomasyon

Repo: [github.com/azubalo/telc](https://github.com/azubalo/telc) (önceki telc içeriği kaldırıldı; bu proje yerleştirildi.)

WordPress eklentisi: yayınlanmış yazılardan `blog_post` taslağı üretir (Ollama).

## GitHub ≠ Ollama erişimi

Bu repodaki kodları GitHub’dan indirmek **eklentiyi kurmana** yarar; **DreamHost’taki sitenin senin bilgisayarındaki Ollama’ya bağlanması** ayrı bir konudur. Bunun için tünel (ngrok vb.), evde çalışmayan bir **bulut LLM API** (eklentiye ayrıca eklenmesi gerekir) veya **Ollama’nın herkese açık bir sunucuda** çalışması gerekir.

## WordPress’e eklenti yükleme (ZIP)

1. GitHub’da **Code → Download ZIP** (veya bu repoyu klonla).
2. Zip içinden klasör: `wordpress-plugin/printonnow-blog-curator/`
3. Bu klasörü yeniden zip’le (`printonnow-blog-curator.zip`); içinde doğrudan `printonnow-blog-curator.php` görünsün.
4. WordPress: **Eklentiler → Yeni ekle → Eklenti yükle** → zip’i seç.

## Yerel PHP aracı (isteğe bağlı)

`index.php`, `baslat.bat`, `kur-php.ps1` masaüstü ortamı içindir; canlı WordPress zorunlu değildir.

## Tünel betikleri

`ollama-tunnel*.bat` ve `ngrok-authtoken-kur.bat` Ollama’yı dışarı açmak içindir; GitHub’dan çekmek bunların yerine geçmez.
