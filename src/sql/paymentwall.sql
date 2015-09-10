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
-- AUTO_INCREMENT for table `pw_delivery_data`
--
ALTER TABLE `pw_delivery_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=0;