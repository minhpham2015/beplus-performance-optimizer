# Roadmap 1.1.13 → 1.4.0

File theo dõi tiến trình. Khi hỏi "làm tới đâu rồi", đọc file này và cập nhật cột **Trạng thái**.

Trạng thái: `TODO` · `DOING` · `DONE` · `BLOCKED`

Cập nhật lần cuối: 2026-10-25 · Phiên bản hiện tại: 1.1.13 · Nhánh mặc định: `master`

## 1.2.0 — Page cache (lỗ hổng lớn nhất)

| # | Tính năng | Ghi chú | Trạng thái |
|---|---|---|---|
| 1 | Page cache HTML tĩnh (`advanced-cache.php`, ghi vào `wp-content/cache/`) | Drop-in chạy rất sớm: dùng cùng quy trình an toàn như `lib/object-cache.php` (config có guard, thử trước khi cài, fail-safe). | TODO |
| 2 | Invalidation cho page cache | Bắt buộc theo Hard Rule #7: `save_post`, `transition_post_status`, `switch_theme`, `customize_save_after`, comment mới; đăng ký từ `plugins_loaded`. | TODO |
| 3 | Loại trừ cache an toàn | Logged-in, cookie giỏ hàng/WooCommerce, query string, POST, `cache_exclude_pages`. Mặc định tắt. | TODO |
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

## Nhật ký tiến trình

| Ngày | Việc đã làm |
|---|---|
| 2026-10-25 | Lập roadmap, chưa bắt đầu tính năng nào. |
