<?php
$modalNotifications = !empty($allUserNotifications) ? $allUserNotifications : (!empty($currentUser['userID']) && isset($conn) && $conn instanceof mysqli ? get_user_notifications($conn, (int)$currentUser['userID'], 60) : []);
$modalUnreadCount = isset($unreadNotificationCount) ? (int)$unreadNotificationCount : (!empty($currentUser['userID']) && isset($conn) && $conn instanceof mysqli ? get_user_unread_notification_count($conn, (int)$currentUser['userID']) : 0);
?>
<!-- ═══════════════════ ALL NOTIFICATIONS MODAL ═══════════════════ -->
<div class="modal fade" id="allNotificationsModal" tabindex="-1" aria-labelledby="allNotificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 620px;">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <!-- Modal Header -->
            <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); padding: 1.25rem 1.5rem;">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 42px; height: 42px; background: rgba(216, 173, 99, 0.2); border: 1.5px solid rgba(216, 173, 99, 0.6); color: #d8ad63;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold text-white mb-0" id="allNotificationsModalLabel">All Notifications</h5>
                            <?php if ($modalUnreadCount > 0): ?>
                                <span class="badge bg-danger rounded-pill px-2 py-1" style="font-size: 0.72rem;"><?= $modalUnreadCount ?> unread</span>
                            <?php endif; ?>
                        </div>
                        <div class="small" style="color: rgba(255, 255, 255, 0.85);">Property updates, viewing alerts, and tracking status</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Subheader / Actions -->
            <div class="d-flex justify-content-between align-items-center px-4 py-2 bg-light border-bottom">
                <span class="small text-muted fw-semibold"><?= count($modalNotifications) ?> notification<?= count($modalNotifications) === 1 ? '' : 's' ?></span>
                <?php if ($modalUnreadCount > 0): ?>
                    <form method="POST" action="notifications_action.php" class="m-0" data-confirm="Mark all notifications as read?">
                        <input type="hidden" name="action" value="mark_all_read">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary fw-semibold" style="font-size: 0.78rem;">Mark all as read</button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">All caught up</span>
                <?php endif; ?>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-0" style="max-height: 60vh; overflow-y: auto;">
                <?php if (!empty($modalNotifications)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($modalNotifications as $notifItem):
                            $isUnread = !(bool) $notifItem['isRead'];
                            $itemLink = !empty($notifItem['link']) ? $notifItem['link'] : 'index.php';
                        ?>
                            <form method="POST" action="notifications_action.php" class="m-0">
                                <input type="hidden" name="action" value="read_and_go">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $notifItem['notificationID'] ?>">
                                <input type="hidden" name="to" value="<?= htmlspecialchars($itemLink, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="list-group-item list-group-item-action p-3 d-flex align-items-start gap-3 border-bottom <?= $isUnread ? 'bg-primary-subtle border-start border-4 border-primary' : '' ?>" style="text-decoration: none; width: 100%; text-align: left;">
                                <span class="rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0 <?= $isUnread ? 'bg-primary text-white shadow-sm' : 'bg-light text-secondary border' ?>" style="width: 38px; height: 38px;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                </span>
                                <span class="flex-grow-1">
                                    <span class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                        <span class="fw-bold <?= $isUnread ? 'text-primary' : 'text-dark' ?>" style="font-size: 0.92rem;"><?= htmlspecialchars($notifItem['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($isUnread): ?>
                                            <span class="badge bg-primary small" style="font-size: 0.65rem;">New</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="d-block mb-1 text-dark fw-normal" style="font-size: 0.82rem; line-height: 1.4;"><?= htmlspecialchars($notifItem['message'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="d-flex align-items-center justify-content-between gap-2 mt-2">
                                        <time class="small text-muted" datetime="<?= htmlspecialchars(date('c', strtotime($notifItem['createdAt'])), ENT_QUOTES, 'UTF-8') ?>" style="font-size: 0.73rem;">
                                            <?= htmlspecialchars(date('M j, Y g:i A', strtotime($notifItem['createdAt'])), ENT_QUOTES, 'UTF-8') ?>
                                        </time>
                                        <span class="text-primary small fw-semibold" style="font-size: 0.75rem;">View details &rarr;</span>
                                    </span>
                                </span>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="px-4 py-5 text-center text-muted">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center p-3 mb-3 border">
                            <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" class="text-secondary"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">No notifications yet</h6>
                        <p class="small text-muted mb-0">When your sales agent updates your requests, verifies documents, or confirms payments, updates will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer border-0 p-3">
                <button type="button" class="btn btn-outline-secondary btn-sm px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
