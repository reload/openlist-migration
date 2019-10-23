CREATE TABLE `searches` (
                            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                            `guid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                            `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                            `list` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                            `query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                            `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                            `changed_at` timestamp(6) NULL DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `searches_guid_query_unique` (`guid`,`query`),
                            KEY `searches_guid_index` (`guid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
