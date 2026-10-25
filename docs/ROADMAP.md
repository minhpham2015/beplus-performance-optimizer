# Roadmap 1.1.13 → 1.4.0

File theo dõi tiến trình. Khi hỏi "làm tới đâu rồi", đọc file này và cập nhật cột **Trạng thái**.

Trạng thái: `TODO` · `DOING` · `REVIEW` (code + test tự động xong, chờ smoke test trên WordPress thật) · `DONE` · `BLOCKED`

Cập nhật lần cuối: 2026-10-26 · Phiên bản hiện tại: 1.1.13 · Nhánh mặc định: `master`

## 1.2.0 — Page cache (lỗ hổng lớn nhất)

| # | Tính năng | Ghi chú | Trạng thái |
|---|---|---|---|
| 1 | Page cache HTML tĩnh (`advanced-cache.php`, ghi vào `wp-content/cache/`) | Stub mỏng + `lib/class-bepluspb-page-cache-engine.php`; có fallback serve từ plugin khi không có `WP_CACHE`/drop-in bị plugin khác chiếm. Config có guard, fail-open. | REVIEW |
| 2 | Invalidation cho page cache | Hard Rule #7: post/comment/menu/widget/theme/term/plugin/option + mọi lần lưu settings + khi xóa file asset. Đăng ký từ `plugins_loaded`. Đã sửa luôn hook UCSS bị "chết" từ v1.0.6. | REVIEW |
| 3 | Loại trừ cache an toàn | Logged-in, cookie giỏ hàng/WooCommerce/comment/password, query string, POST/AJAX/REST, Host lạ, `cache_exclude_pages`, meta box, `DONOTCACHEPAGE`. Mặc định tắt. | REVIEW |
| 4 | Cache preload (sitemap + WP-Cron) | Cần #1 trước. Chỉ gọi URL cùng domain, không thêm SSRF. | TODO |

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

- Đề xuất chia bản 1.2.0 → 1.3.0 → 1.4.0 (page cache cần thời gian test riêng). _Chưa xác nhận: chia bản hay nhảy thẳng 1.4.0._
- Ưu tiên: #1–#3 trước; #6, #7 làm song song; #11 để cuối.

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

## Nhật ký tiến trình

| Ngày | Việc đã làm |
|---|---|
| 2026-10-25 | Lập roadmap, chưa bắt đầu tính năng nào. |
| 2026-10-26 | Hoàn thành code #1–#3 (page cache + invalidation + loại trừ), test standalone `test-page-cache-engine.php` và `test-page-cache-class.php`, CI guard, CHANGELOG, CLAUDE.md rule #8. Chưa smoke test trên WordPress thật. Chưa tăng version (chờ #4 preload hoặc quyết định phát hành). |
