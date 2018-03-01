--
-- Table structure for table `pw_delivery_data`
--

CREATE TABLE IF NOT EXISTS `pw_delivery_data` (
  `id` int(11) NOT NULL,
  `reference_id` varchar(50) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `status` varchar(25) DEFAULT 'unsent',
  `data` text,
  `created_date` int(11) DEFAULT NULL,
  `updated_date` int(11) DEFAULT NULL
) ENGINE=InnoDB;


--
-- Indexes for table `pw_delivery_data`
--
ALTER TABLE `pw_delivery_data`
  ADD PRIMARY KEY (`id`);

--
-- table `pw_delivery_data`
--
ALTER TABLE `pw_delivery_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=0;

CREATE TABLE IF NOT EXISTS `pw_payment_token` (
  `id` int(11) NOT NULL PRIMARY KEY,
  `user_id` int(11) DEFAULT NULL,
  `gateway_id` varchar(50) DEFAULT NULL,
  `token` text DEFAULT NULL,
  `card_type` varchar(255) DEFAULT NULL,
  `card_last_four` varchar(255) DEFAULT NULL,
  `expiry_month` varchar(255) DEFAULT NULL,
  `expiry_year` varchar(255) DEFAULT NULL,
  `created_date` int(11) DEFAULT NULL,
  `updated_date` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=0;