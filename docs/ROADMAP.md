# Roadmap 1.1.14 → 1.4.0

File theo dõi tiến trình. Khi hỏi "làm tới đâu rồi", đọc file này và cập nhật cột **Trạng thái**.

Trạng thái: `TODO` · `DOING` · `REVIEW` (code + test tự động xong, chờ smoke test trên WordPress thật) · `DONE` · `BLOCKED`

Cập nhật lần cuối: 2026-11-12 · Phiên bản hiện tại: 1.2.0 trên `staging` để test (đã chứa Page Cache #1–#4, opt-in, mặc định tắt) · Nhánh mặc định: `master`

> **Rủi ro lớn nhất hiện tại:** #1–#3 đã phát hành trong 1.1.14 nhưng **chưa smoke test trên WordPress thật** (24 mục ở cuối file). Làm việc này trước khi thêm tính năng mới.

## 1.2.0 — Page cache (lỗ hổng lớn nhất) — code đã nằm trong 1.1.14, còn #4 để đóng mốc 1.2.0

| # | Tính năng | Ghi chú | Trạng thái |
|---|---|---|---|
| 1 | Page cache HTML tĩnh (`advanced-cache.php`, ghi vào `wp-content/cache/`) | Stub mỏng + `lib/class-bepluspb-page-cache-engine.php`; có fallback serve từ plugin khi không có `WP_CACHE`/drop-in bị plugin khác chiếm. Config có guard, fail-open. | REVIEW |
| 2 | Invalidation cho page cache | Hard Rule #7: post/comment/menu/widget/theme/term/plugin/option + mọi lần lưu settings + khi xóa file asset. Đăng ký từ `plugins_loaded`. Đã sửa luôn hook UCSS bị "chết" từ v1.0.6. | REVIEW |
| 3 | Loại trừ cache an toàn | Logged-in, cookie giỏ hàng/WooCommerce/comment/password, query string, POST/AJAX/REST, Host lạ, `cache_exclude_pages`, meta box, `DONOTCACHEPAGE`. Mặc định tắt. | REVIEW |
| 4 | Cache preload (WP-Cron) | `class-bepluspb-page-cache-preload.php`, option `page_cache_preload` (mặc định tắt). Danh sách URL lấy từ nội dung đã đăng của chính site (không gọi sitemap từ xa), chỉ gọi URL cùng host, không follow redirect, không cookie, dừng sau 5 lần lỗi liên tiếp, giới hạn 1 lượt chạy/10 phút (chống vòng lặp purge), tự warm lại sau mỗi TTL. TTL Page Cache tối đa 24 giờ. Test `test-page-cache-preload.php` (59 assertion). Chưa smoke test trên WP thật. | REVIEW |

## 1.3.0 — Tải trang và dễ dùng

| # | Tính năng | Ghi chú | Trạng thái |
|---|---|---|---|
| 5 | Critical CSS | Dịch vụ ngoài hoặc dán thủ công. Bản chạy trên máy chủ cần review bảo mật riêng. | TODO |
| 6 | Preconnect / DNS-prefetch + fetchpriority cho ảnh LCP | Công sức nhỏ, thấy hiệu quả ngay. | TODO |
| 7 | Heartbeat control | Không đụng DB. | TODO |
| 8 | Recommended Settings nâng cấp (profile An toàn / Cân bằng / Mạnh, có hoàn tác) | Mở rộng `class-bepluspb-recommendations.php`. | TODO |
| 9 | Chế độ kiểm tra an toàn (bật thử cho admin, `?bepluspb_off=1`) | | TODO |
| 10 | Tự exclude theo plugin/theme phổ biến | Danh sách tĩnh, không gọi mạng. | TODO |

## 1.4.0 — Mở rộng và hoàn thiện

| # | Tính năng | Ghi chú | Trạng thái |
|---|---|---|---|
| 11 | Dọn dẹp Database | **Làm cuối, review bảo mật riêng.** `$wpdb->prepare()`, xem trước, xác nhận, nonce trước capability. Bản đầu chỉ "xem trước số lượng". | TODO |
| 12 | Tự chuyển ảnh sang WebP/AVIF | Kiểm tra GD/Imagick, validate path theo Hard Rule #3. | TODO |
| 13 | Cloudflare: purge theo URL khi sửa bài | Chỉ chạy khi admin kích hoạt. | TODO |
| 14 | Dashboard hiệu năng | Sau khi có page cache. | TODO |
| 15 | Multisite + WP-CLI | | TODO |

## Checklist cho mỗi tính năng/bản phát hành

- [ ] `php -l` các file đã sửa
- [ ] Thêm test trong `tests/`; cập nhật `tests/helpers/admin-methods.txt` nếu thêm/xóa method
- [ ] Cập nhật `CHANGELOG.md`, `BEPLUSPB_VERSION`, `readme.txt`
- [ ] `security-regression-guard` (CI) xanh
- [ ] File/thư mục dev-only mới ở thư mục gốc đã thêm vào exclude của `rsync` trong `docs/RELEASE.md`

## Quyết định đã chốt

- Page cache #1–#3 đã được gộp vào 1.1.14 (opt-in, mặc định tắt) thay vì chờ 1.2.0. Mốc **1.2.0** = #4 preload + smoke test xong + #1–#3 chuyển `DONE`.
- Vẫn chia 1.2.0 → 1.3.0 → 1.4.0. _Chưa xác nhận với chủ dự án; nếu muốn gộp thì nhảy thẳng 1.4.0._
- Ưu tiên: smoke test #1–#3 → #4 → #6, #7 (song song) → #8/#9 → #11 để cuối.

## Hạn chế đã biết của Page Cache

Đã xử lý (2026-10-26, kể cả rủi ro Vary/bài liên quan/CSP nonce): phát lại header PHP khi HIT (`.hdr` sidecar, allowlist), cache riêng cho mobile (`wp_is_mobile()`), biến thể theo cookie ngôn ngữ/tiền tệ (tự nhận WPML/Polylang/WCML), tự xóa toàn bộ khi sửa bài nếu site có widget/block liệt kê nội dung (Recent Posts/Comments, Archives, Calendar, Tag Cloud, Categories, Query Loop trong template part).

Còn lại:
- Header do **web server** đặt thì vẫn do web server tự gửi (không liên quan plugin). Header lạ ngoài allowlist do plugin PHP đặt sẽ không được phát lại.
- Nội dung đổi theo yếu tố khác (geolocation theo IP, A/B test phía server, cookie consent đổi HTML, plugin đa tiền tệ khác WCML) vẫn cần nhập cookie vào "Language / Currency Cookies" hoặc "Bypass Cookies", hoặc loại trừ URL.
- Widget liệt kê nội dung bằng shortcode/PHP tự viết (ngoài danh sách block/widget chuẩn) không được tự nhận ra: chọn "Every cached page" ở "When a Post Is Edited".
- **Theme/plugin tự phát hiện điện thoại bằng code riêng** (không gọi `wp_is_mobile()`): chế độ Automatic không thấy được, chọn "Always keep a separate mobile copy" (chế độ này gửi `Vary: User-Agent` trên mọi trang → cache server như Varnish/nginx fastcgi_cache mất hit-rate).
- **Plugin "related posts" đọc bài bằng SQL thô** (`$wpdb`) thay vì `WP_Query`/`get_posts` không được quan sát; chọn "Every cached page". Tên bài láng giềng ở nút Prev/Next chỉ cập nhật khi hết TTL hoặc khi đăng/gỡ bài.
- Chưa có giới hạn tổng dung lượng/số file (chỉ giới hạn bằng TTL + cron dọn hằng ngày + chặn URL chữ hoa/query string). Cache mobile tách riêng làm số file tăng gần gấp đôi.
- Chưa chạy được `phpcs`/`phpstan` ở môi trường dev (không cài được composer) — chờ CI. (Đã sửa lỗi CI ở commit `9a11656`; xác nhận lại CI xanh trên `master`.)

## Smoke test thủ công trước khi chuyển #1–#3 sang DONE

Môi trường dev chưa có WordPress thật (không tải được wordpress.org), nên các mục dưới chưa được chạy:

1. Bật Cache (Dashboard) + Page Cache, lưu. Kiểm tra có `wp-content/advanced-cache.php` (có marker `BEPLUSPB_PAGE_CACHE_DROPIN`) và `wp-content/.bepluspb_pc.php`.
2. `curl -s http://site/wp-content/.bepluspb_pc.php` → body phải rỗng (không lộ JSON).
3. Không có `WP_CACHE`: tải trang chủ 2 lần ẩn danh → lần 2 có header `X-Beplus-Page-Cache: HIT`. Thêm `define('WP_CACHE', true);` → vẫn HIT (chế độ drop-in).
4. Đăng nhập (cookie) → không HIT; thêm `?s=a`, `?p=1` → không HIT; `?utm_source=x` → HIT.
5. Sửa một bài đã xuất bản → trang bài + trang chủ + archive hết HIT lần đầu; trang khác vẫn HIT. Đăng bài mới / đổi menu / đổi widget → mọi trang hết HIT.
6. Bình luận được duyệt → trang bài hết HIT. Giỏ hàng WooCommerce (cookie `woocommerce_items_in_cart`) → không HIT.
7. Xóa asset cache (Purge ALL) rồi mở trang → CSS/JS vẫn tải được (không 404).
8. Tắt Page Cache → `advanced-cache.php` và config biến mất; nếu plugin khác sở hữu `advanced-cache.php` thì file đó không bị đụng tới.
9. Có Redis Object Cache bật cùng lúc: không xung đột.
10. Header: đặt thử `add_action('send_headers', fn() => header('X-Frame-Options: SAMEORIGIN'));` → lần HIT vẫn có header (kiểm tra bằng `curl -I`).
11. Mobile (Automatic): trang không gọi `wp_is_mobile()` → desktop và `curl -A 'Mozilla/5.0 (iPhone; ... Mobile/15E148)'` cùng HIT, **không** có `Vary: User-Agent`. Với theme gọi `wp_is_mobile()` → hai bản `index-d*`/`index-m*` và có `Vary: User-Agent`.
12. Ngôn ngữ/tiền tệ: với WPML/Polylang/WCML, đổi ngôn ngữ/tiền tệ rồi tải lại → đúng ngôn ngữ/tiền tệ, vẫn HIT.
13. Thêm widget Recent Posts (cổ điển hoặc block), sửa một bài → mọi trang hết HIT; gỡ widget, sửa bài → chỉ trang liên quan + trang có danh sách bài/comment khác hết HIT (card Page Cache hiện "Currently detected").
14. Plugin related-posts (WP_Query): sửa bài A → trang bài B đang hiện A trong "related" hết HIT (có file `index*.lst` cạnh trang đó), trang không liệt kê gì vẫn HIT.
15. CSP nonce: plugin bảo mật có `'nonce-…'` → `curl -si` 2 lần trang HIT: nonce ở header và ở `<script nonce=...>` khớp nhau trong cùng một response và khác giữa hai response.
16. Đổi tiêu đề một trang có trong menu chính → mọi trang khác hiện tên mới ngay (không đợi hết TTL); đổi slug trang cha → URL con cũ không còn HIT.
17. Block theme: sửa header/footer trong Site Editor (hoặc Elementor Theme Builder) → mọi trang hết HIT. Gửi form liên hệ ở front-end (tạo bản ghi CPT) → cache **không** bị xóa.
18. Đổi domain bằng `wp search-replace` hoặc `WP_HOME`/`WP_SITEURL`, mở trang chủ 2 lần → lần 2 có `X-Beplus-Page-Cache: HIT` (không cần vào wp-admin).
19. Cookie lạ: `curl -H 'Cookie: pll_language=zzzz1'` nhiều giá trị → không tạo thêm file; `pll_language=vi` → có bản riêng.
20. Card Page Cache: mục "Compatibility check" **không** hiện cảnh báo vàng. Nếu hiện, Automatic đang chạy như "Always keep a separate mobile copy" và sửa bài xóa toàn bộ cache.
21. Thư mục WordPress có ký tự `[` hoặc `]` (hiếm): Purge ALL vẫn xóa được trang.
22. Bật "WP Maintenance Mode" (nếu dùng) → trang đã cache biến mất ngay, khách thấy trang bảo trì; tắt → trang thường trở lại.
23. Đổi logo/site icon trong Customizer hoặc đơn vị tiền tệ WooCommerce → mọi trang cập nhật. Tác giả đổi tên hiển thị → byline cập nhật.
24. Đăng nhập bằng tài khoản **không phải admin** (vd. Subscriber/Customer) → CSS/JS là file `bepluspb-cache/...` đã minify; đăng nhập **admin** → CSS/JS gốc. Card Page Cache khi tắt không hiện dòng nào; khi bật có một dòng trạng thái ngay dưới ô "Enable Page Cache".

25. **Preload**: bật Preload, lưu → sau ~1–2 phút (cần có traffic hoặc cron thật) card hiện "Preload running/finished: X of Y". `curl -I` trang chủ và vài bài → `X-Beplus-Page-Cache: HIT` ngay lần đầu.
26. Preload + Purge ALL: bấm Purge ALL → preload chạy lại; trang trong `/cart/`, `/checkout/`, `Page Exclusions` **không** được cache.
27. Chặn loopback (vd. firewall chặn site gọi chính nó) → trạng thái "stopped after repeated failed requests", không lặp vô hạn. Tắt Preload hoặc Page Cache → không còn event `bepluspb_page_cache_preload_*` trong WP-Cron (`wp cron event list`).
28. Preload không làm lộ trang cá nhân hóa: sau preload, mở trang bằng tài khoản đăng nhập → không phải bản cache.
29. TTL: nhập 72 vào "Cache Lifespan" → ô giới hạn ở 24; lưu → giá trị còn lại 24 (hoặc nhỏ hơn nếu nhập nhỏ).
30. Preload refresh: đặt TTL 1 giờ, bật Preload, đợi run xong → `wp cron event list` có `bepluspb_page_cache_preload_start` (≥10 phút sau). Bấm Purge ALL nhiều lần liên tiếp → chỉ một lượt crawl mỗi 10 phút.
31. **Delay JS (Simple) + form**: bật Delay JS (Simple), mở trang có form AJAX (Contact Form 7, bình luận, add-to-cart) ở cửa sổ ẩn danh, bấm gửi ngay khi trang vừa hiện → form gửi bằng AJAX như bình thường (không reload trang), chỉ gửi **một** lần dù bấm 2 lần. Form tìm kiếm vẫn chuyển trang.
32. **Defer + Delay cùng bật**: Console không có lỗi "X is not defined"; menu, slider, giỏ hàng chạy đúng.

## Nhật ký tiến trình

| Ngày | Việc đã làm |
|---|---|
| 2026-10-25 | Lập roadmap, chưa bắt đầu tính năng nào. |
| 2026-10-26 | Hoàn thành code #1–#3 (page cache + invalidation + loại trừ), test standalone `test-page-cache-engine.php` và `test-page-cache-class.php`, CI guard, CHANGELOG, CLAUDE.md rule #8. Chưa smoke test trên WordPress thật. Chưa tăng version (chờ #4 preload hoặc quyết định phát hành). |
| 2026-10-26 | Merge #1–#3 vào `master` để test. |
| 2026-10-26 | Tự review #1–#3, sửa 5 lỗi (xem CHANGELOG "Fixed"), thêm 20 assertion. Các sửa này nằm ở nhánh `claude/modest-mccarthy-lheo63`, chưa vào `master`. |
| 2026-10-26 | Review lần 2: sửa header replay bị trùng/mâu thuẫn ở chế độ fallback và cookie có dấu `.` không khớp (+5 assertion). |
| 2026-10-26 | Xử lý 3 hạn chế còn lại: replay header, biến thể mobile/ngôn ngữ/tiền tệ, purge scope theo widget toàn site. Test engine 162 + class 163 assertion. Nằm ở nhánh `claude/modest-mccarthy-lheo63`. |
| 2026-10-26 | Xử lý 3 rủi ro: mobile "Automatic" (quan sát `wp_is_mobile`, chỉ Vary trên trang phụ thuộc thiết bị), đánh dấu trang liệt kê nội dung khác (quan sát `pre_get_posts`/`pre_get_comments`, `.lst`), xoay CSP nonce mỗi HIT. Test engine 203 + class 185 assertion; đã kiểm tra bằng 8 đột biến (phá tính năng → test fail). |
| 2026-10-26 | Review lần 3 → sửa #1–#4: giới hạn giá trị cookie biến thể + tối đa 96 biến thể/URL (+ lỗi `$` nhận ký tự xuống dòng), purge toàn bộ khi đổi tiêu đề/slug/cha/thứ tự bài đã đăng, purge khi sửa template/template part/global styles/navigation/block/builder/form (phải là người có quyền sửa bài hoặc WP-CLI), tự sửa config khi đổi địa chỉ site. Test engine 236 + class 242 assertion; 12 đột biến đều bị test bắt. Chưa sửa #5–#8 (glob có ký tự đặc biệt, option/tác giả chưa hook, quan sát chưa kiểm chứng trên WP thật, rủi ro vốn có của page cache). |
| 2026-10-26 | Sửa #5–#8: bỏ `glob()` (đường dẫn có `[ ] * ?`), kiểm tra sống các hook quan sát + fallback an toàn + bỏ qua trang khi `wp_is_mobile()` lệch cách phân loại + chuẩn hóa `%xx`, thêm option/WooCommerce/tác giả vào danh sách xóa cache + ô cài đặt riêng, liệt kê plugin bị bỏ qua khi HIT, dọn rác khi tắt WP-Cron. Test engine 262 + class 299 assertion; 18 đột biến đều bị bắt. |
| 2026-10-27 | Bỏ option `cache_for_logged_in` + card "User-Based Exclusions" (admin thấy file gốc, người đăng nhập khác nhận file minify; Minify khởi động sau cổng admin; gộp hàm loại trừ trùng lặp trong Minify). Bỏ hàng "Status" của card Page Cache: dòng trạng thái dưới ô bật, ẩn khi tắt, thông báo chỉ hiện khi cần. Test mới `test-page-cache-settings-ui.php` (33 assertion), 9 đột biến đều bị bắt. |
| 2026-10-28 | Phát hành 1.1.14: gộp Page Cache (#1–#3), bỏ option logged-in CSS/JS, sửa lỗi CI (phpcs/phpstan). |
| 2026-11-06 | Smoke test mục 1–8 (cài đặt, loại trừ cơ bản, invalidation cơ bản, purge, tắt/bật) đạt trên WordPress thật; mục 9–24 chưa chạy. |
| 2026-11-06 | Làm #4 preload (REVIEW): class mới, option, UI, hook purge, uninstall, CHANGELOG, CLAUDE.md. Tất cả test hiện có vẫn pass. Cần smoke test #25–#28 bên dưới. |
| 2026-11-06 | Rà soát #1–#4, xử lý 3 điểm: TTL tối đa 24h (nonce), preload tự warm lại sau TTL, rate limit 1 lượt/10 phút. Thêm smoke test #29–#30. |
| 2026-11-06 | Tăng version 1.2.0 (readme, CHANGELOG, header), merge sang `staging` để smoke test #9–#30. Chưa vào `master`/SVN. |
| 2026-11-06 | Rà soát roadmap: đồng bộ với 1.1.14, sắp lại nhật ký theo thời gian, chốt 5 việc ưu tiên (smoke test → #4 → #6 → #7 → #8). Chưa có thay đổi code. |
| 2026-11-12 | Merge nhánh `claude/busy-fermi-af0fhn` (bị sót từ 2026-10-22) vào `staging`: Delay JS (Simple) giữ form submit tới khi script chạy xong, không delay script cần cho inline 'after', chú thích Defer/Delay. Review sau merge sửa thêm: bấm 2 lần gửi form 2 lần, form có field tên `submit` không gửi được trên trình duyệt thiếu `requestSubmit()`, Defer làm dependency chạy sau script cần nó. Test mới `test-delay-js-inline-after-deps.php`. Cần smoke test #31–#32. |

## Việc còn lại từ review lần 3

Đã xử lý hết #1–#8 (xem nhật ký). Còn lại là bản chất của mọi page cache, đã hiển thị trong màn hình cài đặt:

- Khi HIT, code của plugin khác không chạy: tường lửa/chặn IP, chế độ bảo trì, age-gate, redirect mới thêm, bộ đếm lượt xem server-side. Màn hình cài đặt liệt kê plugin đang bật thuộc nhóm này; chế độ bảo trì và tùy chọn cài đặt khác dùng ô "Extra Settings That Clear the Cache".
- Quan sát `pre_get_posts` không thấy plugin đọc bài bằng SQL thô (`$wpdb`): chọn "Every cached page". Tên bài láng giềng ở Prev/Next chỉ cập nhật khi hết TTL hoặc khi đăng/gỡ bài.
- Còn cần **smoke test trên WordPress thật** (xem checklist) — kiểm tra "Compatibility check" trong card Page Cache không báo lỗi.

## Smoke test: Object Cache drop-in khi cấu hình tắt/mất (nhánh `claude/fix-oc-dropin-fatal`)

Chưa chạy trên WordPress thật. Test tự động dùng file `cache.php` giả của core.

- **O1** Site đang chạy drop-in BẢN CŨ (1.2.0 trước bản sửa): vào tab Object Cache → có cảnh báo vàng "older Beplus build". Bấm Install Drop-in → cảnh báo biến mất, Object Cache vẫn kết nối Redis (`wp_using_ext_object_cache()` = true, có key `bepluspb:*` trong Redis).
- **O2** Bỏ tick "Enable Object Cache", lưu → site và wp-admin vẫn lên (HTTP 200, `debug.log` không có `Cannot redeclare`). Site Health không còn báo có persistent object cache; transient lại nằm trong bảng options.
- **O3** Xóa tay `wp-content/.bepluspb_oc.php` khi drop-in MỚI đang cài → site vẫn lên, cache mặc định của WordPress hoạt động.
- **O4** Drop-in CŨ đang cài, bỏ tick Enable rồi lưu → drop-in cũ tự bị gỡ (file `object-cache.php` biến mất), site không chết. Nếu `wp-content` không ghi được: lưu báo lỗi, config KHÔNG bị ghi thành tắt.
- **O5** Gỡ plugin (Delete) khi drop-in còn cài → `object-cache.php` và `.bepluspb_oc.php` cùng biến mất; nếu không xóa được drop-in thì config được giữ lại.

