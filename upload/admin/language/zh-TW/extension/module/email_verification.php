<?php
// Heading
$_['heading_title']    = 'Email Verification';

// Text
$_['text_edit']        = 'Edit Module';
$_['text_extension']   = 'Extensions';
$_['text_success']     = 'Success: You have modified module!';
$_['text_approve_subject']  = 'Verify your email address';
$_['text_approve_content1'] = 'To activate your account click on the link below or copy/paste into browser address bar:&lt;/div&gt;&lt;table width=&quot;100%&quot; cellpadding=&quot;0&quot; cellspacing=&quot;0&quot; style=&quot;width:100%&quot;&gt;&lt;tbody&gt;&lt;tr&gt;&lt;td&gt;   &lt;div class=&quot;table-responsive&quot;&gt;   &lt;table class=&quot;link&quot; cellspacing=&quot;0&quot; cellpadding=&quot;0&quot; width=&quot;auto&quot; style=&quot;width:auto;&quot;&gt;        &lt;tbody&gt;            &lt;tr&gt;                &lt;td&gt;&lt;a href=&quot;{{ verification_link|replace({\'&amp;amp;\':\'&amp;amp;\'}) }}&quot; target=&quot;_blank&quot;&gt;&lt;b&gt;{{ verification_link }}&lt;/b&gt; &lt;/a&gt;                &lt;/td&gt;            &lt;/tr&gt;        &lt;/tbody&gt;    &lt;/table&gt; &lt;/div&gt;  &lt;/td&gt;&lt;/tr&gt;&lt;/tbody&gt;&lt;/table&gt;&lt;div&gt;&lt;br&gt;Upon logging in, you will be able to access other services including reviewing past orders, printing invoices and editing your account information.    &lt;br&gt;&lt;br&gt;&lt;/div&gt;&lt;div class=&quot;last&quot;&gt;Thanks,    &lt;br style=&quot;line-height:18px;&quot;&gt;&lt;a href=&quot;{{ store_url|replace({\'&amp;amp;\':\'&amp;amp;\'}) }}&quot; target=&quot;_blank&quot;&gt;&lt;b&gt;{{ store_name }}&lt;/b&gt;&lt;/a&gt;&lt;/div&gt;';
$_['text_approve_heading']  = 'Welcome to {{ store_name }}';

// Entry
$_['entry_status']     = 'Status';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify module!';