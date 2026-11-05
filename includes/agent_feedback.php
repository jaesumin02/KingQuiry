<?php
function ensure_agent_reviews_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS agent_reviews (
            reviewID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transactionID BIGINT UNSIGNED NOT NULL,
            clientID INT NOT NULL,
            agentID INT DEFAULT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comment TEXT DEFAULT NULL,
            createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (reviewID),
            UNIQUE KEY uq_agent_reviews_transaction (transactionID),
            KEY idx_agent_reviews_client (clientID),
            KEY idx_agent_reviews_agent (agentID),
            KEY idx_agent_reviews_rating (rating),
            CONSTRAINT fk_agent_reviews_transaction
                FOREIGN KEY (transactionID)
                REFERENCES property_transactions (transactionID)
                ON DELETE CASCADE,
            CONSTRAINT fk_agent_reviews_client
                FOREIGN KEY (clientID)
                REFERENCES users (userID)
                ON DELETE CASCADE,
            CONSTRAINT fk_agent_reviews_agent
                FOREIGN KEY (agentID)
                REFERENCES users (userID)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function get_agent_feedback_summary(mysqli $conn): array
{
    ensure_agent_reviews_table($conn);

    $summary = $conn->query(
        "SELECT COUNT(*) AS review_count,
                ROUND(AVG(rating), 2) AS average_rating,
                MIN(rating) AS min_rating,
                MAX(rating) AS max_rating
         FROM agent_reviews"
    )->fetch_assoc();

    return [
        'review_count' => (int) ($summary['review_count'] ?? 0),
        'average_rating' => (float) ($summary['average_rating'] ?? 0.0),
        'min_rating' => (int) ($summary['min_rating'] ?? 0),
        'max_rating' => (int) ($summary['max_rating'] ?? 0),
    ];
}

function get_agent_feedback_top_performers(mysqli $conn, int $limit = 5): array
{
    ensure_agent_reviews_table($conn);

    $limit = max(1, min(10, $limit));
    $stmt = $conn->prepare(
        "SELECT u.userID,
                u.fullname,
                COUNT(ar.reviewID) AS review_count,
                ROUND(AVG(ar.rating), 2) AS average_rating
         FROM agent_reviews ar
         JOIN users u ON u.userID = ar.agentID
         WHERE ar.agentID IS NOT NULL
         GROUP BY u.userID, u.fullname
         ORDER BY average_rating DESC, review_count DESC, u.fullname ASC
         LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
