<?php
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::THEME,
    'frontend/Icecube/theme', // Make sure this matches your Vendor and Theme name
    __DIR__
);
