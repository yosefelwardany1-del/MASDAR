<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Lookup;
use App\Models\OrderStepDefinition;
use App\Models\PostingRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * MASDAR's starting lists, order steps, chart of accounts and posting rules. Runs on every
 * deploy (docker/entrypoint.sh), so it only ever ADDS what is missing: anything MASDAR has
 * edited, renamed or switched off on the Lists & options or Accounting screens stays as they
 * left it. Every value here is a starting point for an OPEN question in the spec, not a
 * decision.
 */
class MasdarReferenceSeeder extends Seeder
{
    /** list => [code, English, Arabic, Chinese, meta] in order. */
    private const LOOKUPS = [
        'unit' => [
            ['pcs', 'Pieces', 'قطعة', '件'], ['set', 'Sets', 'طقم', '套'], ['dozen', 'Dozens', 'دستة', '打'],
            ['pair', 'Pairs', 'زوج', '双'], ['box', 'Boxes', 'علبة', '盒'], ['roll', 'Rolls', 'لفة', '卷'],
            ['kg', 'Kilograms', 'كيلوجرام', '千克'], ['m', 'Metres', 'متر', '米'],
        ],
        'currency' => [
            ['USD', 'US dollar', 'دولار أمريكي', '美元'], ['CNY', 'Chinese yuan (RMB)', 'يوان صيني', '人民币'],
            ['HKD', 'Hong Kong dollar', 'دولار هونج كونج', '港币'], ['EUR', 'Euro', 'يورو', '欧元'],
            ['AED', 'UAE dirham', 'درهم إماراتي', '阿联酋迪拉姆'], ['SAR', 'Saudi riyal', 'ريال سعودي', '沙特里亚尔'],
            ['EGP', 'Egyptian pound', 'جنيه مصري', '埃及镑'],
        ],
        'container_type' => [
            ['20GP', "20' standard", 'حاوية 20 قدم', '20尺普柜', ['cbm' => 28, 'max_kg' => 21700]],
            ['40GP', "40' standard", 'حاوية 40 قدم', '40尺普柜', ['cbm' => 58, 'max_kg' => 26500]],
            ['40HQ', "40' high cube", 'حاوية 40 قدم عالية', '40尺高柜', ['cbm' => 68, 'max_kg' => 26500]],
            ['LCL', 'Shared container (LCL)', 'شحن جزئي (LCL)', '拼箱'],
        ],
        // The order IS the path a container moves along (I8): keep it in the order things happen.
        'container_status' => [
            ['booked', 'Booked', 'تم الحجز', '已订舱'],
            ['empty_at_warehouse', 'Empty container at warehouse', 'الحاوية الفارغة في المستودع', '空柜到仓'],
            ['loading', 'Loading', 'جاري التحميل', '装柜中'],
            ['loaded_sealed', 'Loaded & sealed', 'تم التحميل والختم', '已装柜封条'],
            ['gate_in', 'Gated in at port', 'دخلت الميناء', '已进港'],
            ['on_board', 'On board – departed', 'على السفينة – غادرت', '已装船离港'],
            ['in_transit', 'In transit', 'في الطريق', '运输中'],
            ['arrived', 'Arrived at port of discharge', 'وصلت ميناء التفريغ', '已到港'],
            ['released', 'Released / cleared', 'تم الإفراج', '已放行'],
            ['delivered', 'Delivered to client', 'سُلّمت للعميل', '已交付', ['final' => true]],
        ],
        'port' => [
            ['NGB', 'Ningbo', 'نينغبو', '宁波', ['side' => 'loading']], ['SHA', 'Shanghai', 'شنغهاي', '上海', ['side' => 'loading']],
            ['YIW', 'Yiwu (rail / inland)', 'ييوو (سكة حديد)', '义乌', ['side' => 'loading']],
            ['SZX', 'Shenzhen', 'شنتشن', '深圳', ['side' => 'loading']], ['CAN', 'Guangzhou', 'قوانغتشو', '广州', ['side' => 'loading']],
            ['JEA', 'Jebel Ali', 'جبل علي', '杰贝阿里', ['side' => 'discharge']], ['JED', 'Jeddah', 'جدة', '吉达', ['side' => 'discharge']],
            ['DMM', 'Dammam', 'الدمام', '达曼', ['side' => 'discharge']], ['ALY', 'Alexandria', 'الإسكندرية', '亚历山大', ['side' => 'discharge']],
            ['PSD', 'Port Said', 'بورسعيد', '塞得港', ['side' => 'discharge']], ['SOK', 'Ain Sokhna', 'العين السخنة', '艾因苏赫纳', ['side' => 'discharge']],
            ['UQR', 'Umm Qasr', 'أم قصر', '乌姆盖斯尔', ['side' => 'discharge']],
        ],
        'shipping_mode' => [
            ['sea_fcl', 'Sea – full container', 'بحري – حاوية كاملة', '海运整柜'], ['sea_lcl', 'Sea – shared container', 'بحري – شحن جزئي', '海运拼箱'],
            ['air', 'Air', 'جوي', '空运'], ['rail', 'Rail', 'سكة حديد', '铁路'], ['express', 'Express courier', 'بريد سريع', '快递'],
        ],
        'shipping_company_kind' => [
            ['forwarder', 'Freight forwarder', 'وكيل شحن', '货代'], ['shipping_line', 'Shipping line', 'خط ملاحي', '船公司'],
            ['trucking', 'Trucking', 'نقل بري', '拖车'], ['customs_broker', 'Customs broker', 'مخلص جمركي', '报关行'],
        ],
        'party_source' => [
            ['referral', 'Referral', 'ترشيح', '推荐'], ['exhibition', 'Exhibition / fair', 'معرض', '展会'],
            ['social_media', 'Social media', 'وسائل التواصل', '社交媒体'], ['website', 'Website', 'الموقع الإلكتروني', '网站'],
            ['walk_in', 'Market visit', 'زيارة مباشرة', '上门'], ['other', 'Other', 'أخرى', '其他'],
        ],
        // from_documents: the stage follows the client's PI, orders and containers (spec §5.1); closed: not chased.
        'client_stage' => [
            ['lead', 'Lead', 'عميل محتمل', '潜在客户'], ['inquiry', 'Inquiry', 'استفسار', '询价'],
            ['pi_sent', 'PI sent', 'أُرسلت الفاتورة المبدئية', '已发形式发票', ['from_documents' => 1]],
            ['confirmed', 'Order confirmed', 'تم تأكيد الطلب', '订单已确认', ['from_documents' => 1]],
            ['in_production', 'In production', 'قيد الإنتاج', '生产中', ['from_documents' => 1]],
            ['shipped', 'Shipped', 'تم الشحن', '已发货', ['from_documents' => 1]],
            ['delivered', 'Delivered', 'تم التسليم', '已交付', ['from_documents' => 1]],
            ['dormant', 'Dormant', 'غير نشط', '休眠', ['closed' => 1]],
        ],
        'interaction_channel' => [
            ['call', 'Phone call', 'مكالمة', '电话'], ['whatsapp', 'WhatsApp', 'واتساب', 'WhatsApp'],
            ['wechat', 'WeChat', 'ويتشات', '微信'], ['email', 'E-mail', 'بريد إلكتروني', '邮件'],
            ['meeting', 'Meeting', 'اجتماع', '会议'], ['factory_visit', 'Factory visit', 'زيارة مصنع', '工厂拜访'],
        ],
        'country' => [
            ['CN', 'China', 'الصين', '中国'], ['HK', 'Hong Kong', 'هونج كونج', '香港'], ['EG', 'Egypt', 'مصر', '埃及'],
            ['SA', 'Saudi Arabia', 'السعودية', '沙特阿拉伯'], ['AE', 'United Arab Emirates', 'الإمارات', '阿联酋'],
            ['IQ', 'Iraq', 'العراق', '伊拉克'], ['JO', 'Jordan', 'الأردن', '约旦'], ['KW', 'Kuwait', 'الكويت', '科威特'],
            ['QA', 'Qatar', 'قطر', '卡塔尔'], ['OM', 'Oman', 'عُمان', '阿曼'], ['BH', 'Bahrain', 'البحرين', '巴林'],
            ['YE', 'Yemen', 'اليمن', '也门'], ['LY', 'Libya', 'ليبيا', '利比亚'], ['DZ', 'Algeria', 'الجزائر', '阿尔及利亚'],
            ['MA', 'Morocco', 'المغرب', '摩洛哥'], ['TN', 'Tunisia', 'تونس', '突尼斯'], ['SD', 'Sudan', 'السودان', '苏丹'],
            ['LB', 'Lebanon', 'لبنان', '黎巴嫩'], ['SY', 'Syria', 'سوريا', '叙利亚'], ['PS', 'Palestine', 'فلسطين', '巴勒斯坦'],
        ],
        // HR (spec §5.7): starting points for Q-H1–Q-H2, all editable.
        'department' => [
            ['management', 'Management', 'الإدارة', '管理层'], ['sales', 'Sales', 'المبيعات', '销售部'],
            ['merchandising', 'Merchandising (order follow-up)', 'متابعة الطلبات', '跟单部'], ['quality', 'Warehouse & inspection', 'المستودع والفحص', '仓库及质检'],
            ['logistics', 'Logistics', 'الشحن', '物流部'], ['finance', 'Finance', 'المالية', '财务部'], ['admin', 'Administration', 'الشؤون الإدارية', '行政部'],
        ],
        'work_location' => [
            ['yiwu_office', 'Yiwu office', 'مكتب ييوو', '义乌办公室'], ['warehouse', 'Warehouse', 'المستودع', '仓库'],
            ['hong_kong', 'Hong Kong', 'هونج كونج', '香港'], ['remote', 'Remote', 'عن بُعد', '远程'],
        ],
        'employment_type' => [
            ['full_time', 'Full time', 'دوام كامل', '全职'], ['part_time', 'Part time', 'دوام جزئي', '兼职'],
            ['intern', 'Intern', 'متدرب', '实习生'], ['contractor', 'Contractor', 'متعاقد', '外包'],
        ],
        'employee_document' => [
            ['passport', 'Passport', 'جواز السفر', '护照'], ['residence_permit', 'Residence permit', 'الإقامة', '居留许可'],
            ['work_permit', 'Work permit', 'تصريح العمل', '工作许可'], ['visa', 'Visa', 'التأشيرة', '签证'],
            ['national_id', 'National ID card', 'البطاقة الشخصية', '身份证'], ['contract', 'Labour contract', 'عقد العمل', '劳动合同'],
            ['health_check', 'Health check', 'الفحص الطبي', '体检'], ['other', 'Other', 'أخرى', '其他'],
        ],
        // days_per_year: blank = not counted; paid: 0 = unpaid (payroll deducts the days).
        'leave_type' => [
            ['annual', 'Annual leave', 'إجازة سنوية', '年假', ['days_per_year' => 5, 'paid' => 1]],
            ['sick', 'Sick leave', 'إجازة مرضية', '病假', ['paid' => 1]],
            ['unpaid', 'Unpaid leave', 'إجازة بدون أجر', '事假', ['paid' => 0]],
            ['marriage', 'Marriage leave', 'إجازة زواج', '婚假', ['days_per_year' => 3, 'paid' => 1]],
            ['maternity', 'Maternity leave', 'إجازة أمومة', '产假', ['days_per_year' => 158, 'paid' => 1]],
            ['paternity', 'Paternity leave', 'إجازة أبوة', '陪产假', ['days_per_year' => 15, 'paid' => 1]],
            ['bereavement', 'Bereavement leave', 'إجازة وفاة', '丧假', ['days_per_year' => 3, 'paid' => 1]],
        ],
        // Staff expense claims (spec §5.8); meta account: the account a line of that kind is booked to.
        'expense_category' => [
            ['travel', 'Travel and transport', 'السفر والمواصلات', '差旅交通', ['account' => '6502']],
            ['meals', 'Meals and client entertainment', 'الوجبات وضيافة العملاء', '餐费及客户招待', ['account' => '6599']],
            ['communications', 'Phone and internet', 'الهاتف والإنترنت', '通讯费', ['account' => '6501']],
            ['courier', 'Courier and samples postage', 'البريد السريع وإرسال العينات', '快递及寄样费', ['account' => '6599']],
            ['samples', 'Samples bought', 'شراء العينات', '样品采购', ['account' => '6599']],
            ['office', 'Office supplies', 'مستلزمات المكتب', '办公用品', ['account' => '6599']],
            ['inspection', 'Inspection on site', 'الفحص في الموقع', '现场验货', ['account' => '5301']],
            ['other', 'Other', 'أخرى', '其他', ['account' => '6599']],
        ],
        // What MASDAR charges besides goods (spec §5.8); meta account: the income account a line is credited to.
        'service_income' => [
            ['commission', 'Sourcing commission', 'عمولة التوريد', '采购佣金', ['account' => '4201']],
            ['inspection', 'Inspection and quality control', 'الفحص ومراقبة الجودة', '验货及质检', ['account' => '4301']],
            ['sourcing', 'Sourcing and factory visits', 'التوريد وزيارات المصانع', '寻源及验厂', ['account' => '4301']],
            ['samples', 'Samples and courier', 'العينات والبريد السريع', '样品及快递', ['account' => '4301']],
            ['freight', 'Freight and port charges recharged', 'الشحن ورسوم الموانئ المحمّلة', '代收运费及港杂费', ['account' => '4401']],
            ['other', 'Other services', 'خدمات أخرى', '其他服务', ['account' => '4301']],
        ],
        // What a quality claim is about (spec §5.8).
        'claim_type' => [
            ['shortage', 'Short shipped or missing cartons', 'نقص في الكمية أو الكراتين', '短装或缺箱'],
            ['defect', 'Defective goods', 'بضاعة معيبة', '质量缺陷'],
            ['wrong_item', 'Wrong item, colour or size', 'صنف أو لون أو مقاس خطأ', '错货、错色或错码'],
            ['damage', 'Damaged in transit or storage', 'تلف أثناء النقل أو التخزين', '运输或仓储损坏'],
            ['packing', 'Packing or labels not as agreed', 'التغليف أو الملصقات مخالفة للاتفاق', '包装或标签不符'],
            ['late_delivery', 'Late delivery', 'تأخر التسليم', '延迟交货'],
            ['other', 'Other', 'أخرى', '其他'],
        ],
    ];

    /** S2's columns, in S2's order: [code, English, Arabic, Chinese, kind, needs a file, due days before delivery]. */
    private const STEPS = [
        ['contract_stamped', 'Order contract confirmation & stamp', 'تأكيد عقد الطلب والختم', '订单合同确认盖章', 'manual', true, null],
        ['uploaded_to_folder', 'Order uploaded to designated folder', 'رفع الطلب إلى المجلد المخصص', '订单上传至指定文件夹', 'manual', false, null],
        ['deposit', 'Deposit', 'العربون', '定金', 'payment', false, null],
        ['initial_sample', 'Initial sample confirmation', 'تأكيد العينة الأولية', '初样确认', 'manual', false, 21],
        ['artwork', 'Artwork confirmed', 'اعتماد التصميم', '设计稿确认', 'manual', false, 21],
        ['final_sample', 'Final sample confirmation', 'تأكيد العينة النهائية', '大货样确认', 'manual', false, 10],
        ['labeling_packaging', 'Labeling and packaging', 'الملصقات والتغليف', '贴标与包装', 'manual', false, 3],
        ['receive_goods', 'Examine & receive goods & update in the system', 'فحص واستلام البضاعة وتحديث النظام', '验货收货并更新系统', 'receipt', false, 0],
        ['booking', 'Booking shipping', 'حجز الشحن', '订舱', 'booking', false, 0],
        ['loading', 'Loading the container', 'تحميل الحاوية', '装柜', 'loading', false, -3],
        ['final_payment', 'Money transfer to factory', 'تحويل المبلغ للمصنع', '付款给工厂', 'payment', false, -3],
    ];

    /** The draft chart (spec §5.6): [code, English, Arabic, Chinese, type, parent, currency]. Two digits = heading. */
    private const ACCOUNTS = [
        ['1', 'Assets', 'الأصول', '资产', 'asset', null, null],
        ['11', 'Cash and bank — Hong Kong', 'النقدية والبنوك — هونج كونج', '现金及银行—香港', 'asset', '1', null],
        ['1101', 'Hong Kong bank — USD', 'بنك هونج كونج — دولار', '香港银行—美元', 'asset', '11', 'USD'],
        ['1102', 'Hong Kong bank — HKD', 'بنك هونج كونج — دولار هونج كونج', '香港银行—港币', 'asset', '11', 'HKD'],
        ['12', 'Cash and bank — mainland China', 'النقدية والبنوك — الصين', '现金及银行—中国内地', 'asset', '1', null],
        ['1201', 'Mainland bank — RMB', 'بنك في الصين — يوان', '内地银行—人民币', 'asset', '12', 'CNY'],
        ['1202', 'Alipay', 'علي باي', '支付宝', 'asset', '12', 'CNY'],
        ['1203', 'WeChat Pay', 'ويتشات باي', '微信支付', 'asset', '12', 'CNY'],
        ['1204', 'Cash — RMB', 'نقدية — يوان', '现金—人民币', 'asset', '12', 'CNY'],
        ['1205', 'Cash — USD', 'نقدية — دولار', '现金—美元', 'asset', '12', 'USD'],
        ['13', 'Money in transit', 'أموال في الطريق', '在途资金', 'asset', '1', null],
        ['1301', 'Transfers between own accounts', 'تحويلات بين حساباتنا', '内部转账', 'asset', '13', null],
        ['14', 'Client receivables', 'ذمم العملاء', '应收客户款', 'asset', '1', null],
        ['1401', 'Client receivables', 'ذمم العملاء', '应收客户款', 'asset', '14', null],
        ['15', 'Deposits paid to suppliers', 'عرابين مدفوعة للموردين', '预付供应商定金', 'asset', '1', null],
        ['1501', 'Deposits paid to suppliers', 'عرابين مدفوعة للموردين', '预付供应商定金', 'asset', '15', null],
        ['16', 'Goods received, not yet shipped', 'بضاعة مستلمة لم تُشحن', '已收未发货物', 'asset', '1', null],
        ['1601', 'Goods received, not yet shipped', 'بضاعة مستلمة لم تُشحن', '已收未发货物', 'asset', '16', null],
        ['17', 'Other receivables and prepayments', 'مدينون آخرون ومصروفات مقدمة', '其他应收及预付', 'asset', '1', null],
        ['1701', 'Rent deposits', 'تأمينات إيجار', '租金押金', 'asset', '17', null],
        ['1702', 'Prepaid expenses', 'مصروفات مدفوعة مقدماً', '预付费用', 'asset', '17', null],
        ['1703', 'Staff advances', 'سلف الموظفين', '员工借支', 'asset', '17', null],
        ['18', 'Fixed assets', 'الأصول الثابتة', '固定资产', 'asset', '1', null],
        ['1801', 'Office equipment', 'معدات مكتبية', '办公设备', 'asset', '18', null],
        ['1802', 'Accumulated depreciation', 'مجمع الإهلاك', '累计折旧', 'asset', '18', null],
        ['2', 'Liabilities', 'الخصوم', '负债', 'liability', null, null],
        ['21', 'Client deposits received', 'عرابين مستلمة من العملاء', '预收客户定金', 'liability', '2', null],
        ['2101', 'Client deposits received', 'عرابين مستلمة من العملاء', '预收客户定金', 'liability', '21', null],
        ['22', 'Supplier payables', 'ذمم الموردين', '应付供应商款', 'liability', '2', null],
        ['2201', 'Supplier payables', 'ذمم الموردين', '应付供应商款', 'liability', '22', null],
        ['23', 'Shipping company payables', 'ذمم شركات الشحن', '应付货代款', 'liability', '2', null],
        ['2301', 'Shipping company payables', 'ذمم شركات الشحن', '应付货代款', 'liability', '23', null],
        ['24', 'Accrued expenses', 'مصروفات مستحقة', '应计费用', 'liability', '2', null],
        ['2401', 'Accrued expenses', 'مصروفات مستحقة', '应计费用', 'liability', '24', null],
        ['2402', 'Salaries payable', 'رواتب مستحقة', '应付工资', 'liability', '24', null],
        ['2403', 'Social insurance and housing fund payable', 'تأمينات اجتماعية وصندوق إسكان مستحقة', '应付社保及公积金', 'liability', '24', null],
        ['2404', 'Individual income tax withheld', 'ضريبة دخل الأفراد المستقطعة', '代扣个人所得税', 'liability', '24', null],
        ['2405', 'Staff expense claims payable', 'مطالبات مصروفات الموظفين المستحقة', '应付员工报销款', 'liability', '24', null],
        ['25', "Directors' current accounts", 'جاري الشركاء', '董事往来', 'liability', '2', null],
        ['2501', "Directors' current accounts", 'جاري الشركاء', '董事往来', 'liability', '25', null],
        ['26', 'Hong Kong profits tax payable', 'ضريبة أرباح هونج كونج المستحقة', '应交香港利得税', 'liability', '2', null],
        ['2601', 'Hong Kong profits tax payable', 'ضريبة أرباح هونج كونج المستحقة', '应交香港利得税', 'liability', '26', null],
        ['3', 'Equity', 'حقوق الملكية', '所有者权益', 'equity', null, null],
        ['31', 'Share capital', 'رأس المال', '股本', 'equity', '3', null],
        ['3101', 'Share capital', 'رأس المال', '股本', 'equity', '31', null],
        ['32', 'Retained earnings', 'الأرباح المحتجزة', '留存收益', 'equity', '3', null],
        ['3201', 'Retained earnings', 'الأرباح المحتجزة', '留存收益', 'equity', '32', null],
        ['39', 'Opening balances', 'الأرصدة الافتتاحية', '期初余额', 'equity', '3', null],
        ['3901', 'Opening balance equity', 'حقوق الأرصدة الافتتاحية', '期初余额权益', 'equity', '39', null],
        ['4', 'Income', 'الإيرادات', '收入', 'income', null, null],
        ['41', 'Sales of goods', 'مبيعات البضائع', '货物销售收入', 'income', '4', null],
        ['4101', 'Sales of goods', 'مبيعات البضائع', '货物销售收入', 'income', '41', null],
        ['4102', 'Sales returns and allowances', 'مردودات ومسموحات المبيعات', '销售退回及折让', 'income', '41', null],
        ['42', 'Commission income', 'إيرادات العمولات', '佣金收入', 'income', '4', null],
        ['4201', 'Commission income', 'إيرادات العمولات', '佣金收入', 'income', '42', null],
        ['43', 'Service fees', 'رسوم الخدمات', '服务费收入', 'income', '4', null],
        ['4301', 'Sourcing and inspection fees', 'رسوم التوريد والفحص', '采购及验货服务费', 'income', '43', null],
        ['44', 'Freight recharged', 'شحن محمّل على العملاء', '代收运费', 'income', '4', null],
        ['4401', 'Freight recharged', 'شحن محمّل على العملاء', '代收运费', 'income', '44', null],
        ['5', 'Cost of sales', 'تكلفة المبيعات', '销售成本', 'expense', null, null],
        ['51', 'Purchases from suppliers', 'مشتريات من الموردين', '采购成本', 'expense', '5', null],
        ['5101', 'Purchases from suppliers', 'مشتريات من الموردين', '采购成本', 'expense', '51', null],
        ['5102', 'Purchase returns and allowances', 'مردودات ومسموحات المشتريات', '采购退回及折让', 'expense', '51', null],
        ['52', 'Freight and port charges', 'الشحن ورسوم الموانئ', '运费及港杂费', 'expense', '5', null],
        ['5201', 'Freight and port charges', 'الشحن ورسوم الموانئ', '运费及港杂费', 'expense', '52', null],
        ['5202', 'Trucking to port', 'النقل إلى الميناء', '拖车费', 'expense', '52', null],
        ['53', 'Inspection, labels and packaging', 'الفحص والملصقات والتغليف', '验货、标签及包装', 'expense', '5', null],
        ['5301', 'Inspection', 'الفحص', '验货费', 'expense', '53', null],
        ['5302', 'Labels and packaging', 'الملصقات والتغليف', '标签及包装', 'expense', '53', null],
        ['6', 'Operating expenses', 'المصروفات التشغيلية', '营业费用', 'expense', null, null],
        ['61', 'Office and warehouse', 'المكتب والمستودع', '办公室及仓库', 'expense', '6', null],
        ['6101', 'Yiwu office rent', 'إيجار مكتب ييوو', '义乌办公室租金', 'expense', '61', null],
        ['6102', 'Warehouse', 'المستودع', '仓库费用', 'expense', '61', null],
        ['62', 'Staff', 'الموظفون', '员工', 'expense', '6', null],
        ['6201', 'Salaries', 'الرواتب', '工资', 'expense', '62', null],
        ['6202', 'Staff visas and residence permits', 'تأشيرات وإقامات الموظفين', '员工签证及居留', 'expense', '62', null],
        ['6203', 'Employer social insurance and housing fund', 'حصة صاحب العمل في التأمينات وصندوق الإسكان', '单位社保及公积金', 'expense', '62', null],
        ['63', 'Company and compliance', 'الشركة والالتزام', '公司及合规', 'expense', '6', null],
        ['6301', 'Hong Kong company secretary and registered office', 'سكرتارية الشركة والعنوان المسجل في هونج كونج', '香港公司秘书及注册地址', 'expense', '63', null],
        ['6302', 'Audit and accounting', 'المراجعة والمحاسبة', '审计及会计', 'expense', '63', null],
        ['64', 'Bank and transfer charges', 'رسوم البنوك والتحويل', '银行及汇款手续费', 'expense', '6', null],
        ['6401', 'Bank and transfer charges', 'رسوم البنوك والتحويل', '银行及汇款手续费', 'expense', '64', null],
        ['65', 'Other operating expenses', 'مصروفات تشغيلية أخرى', '其他营业费用', 'expense', '6', null],
        ['6501', 'Communications', 'الاتصالات', '通讯费', 'expense', '65', null],
        ['6502', 'Travel', 'السفر', '差旅费', 'expense', '65', null],
        ['6599', 'Other expenses', 'مصروفات أخرى', '其他费用', 'expense', '65', null],
        ['66', 'Depreciation', 'الإهلاك', '折旧', 'expense', '6', null],
        ['6601', 'Depreciation of fixed assets', 'إهلاك الأصول الثابتة', '固定资产折旧', 'expense', '66', null],
        ['7', 'Other income and charges', 'إيرادات وأعباء أخرى', '其他收支', 'income', null, null],
        ['71', 'FX gains and losses', 'أرباح وخسائر فروق العملة', '汇兑损益', 'income', '7', null],
        ['7101', 'FX gains and losses', 'أرباح وخسائر فروق العملة', '汇兑损益', 'income', '71', null],
        ['72', 'Bank interest', 'فوائد بنكية', '利息收入', 'income', '7', null],
        ['7201', 'Bank interest', 'فوائد بنكية', '利息收入', 'income', '72', null],
    ];

    /**
     * Automatic postings: event => [debit, credit, what it is]. The money account's own
     * ledger account is the other side where an entry is null. These assume MASDAR buys and
     * resells (a trader); for an agent, point client_invoice's credit at 4201 Commission
     * income — spec Q-A2.
     */
    private const POSTING_RULES = [
        'client_invoice' => ['1401', '4101', 'A PI invoiced to the client when it ships: the client owes the PI total.'],
        'supplier_bill' => ['5101', '2201', 'A supplier order billed when its goods are received: MASDAR owes the factory.'],
        'shipping_bill' => ['5201', '2301', 'A shipping company bill for a container.'],
        'client_receipt' => [null, '1401', 'Money received from a client (deposit or final payment).'],
        'supplier_payment' => ['2201', null, 'Money paid to a supplier (deposit or final payment).'],
        'shipping_payment' => ['2301', null, 'Money paid to a shipping company.'],
        'transfer_out' => ['1301', null, 'Money leaving one of MASDAR\'s own accounts for another.'],
        'transfer_in' => [null, '1301', 'Money arriving in one of MASDAR\'s own accounts from another.'],
        'fx_difference' => ['7101', '7101', 'Exchange differences.'],
        // Payroll (spec §5.7): an approved run, then the salaries paid against it.
        'payroll' => ['6201', '2402', 'A month\'s payroll: gross salaries owed to the staff, net of what is withheld.'],
        'payroll_contributions' => ['6203', '2403', 'Social insurance and housing fund: the employer\'s share as a cost, both shares owed.'],
        'payroll_tax' => [null, '2404', 'Individual income tax withheld from salaries, owed to the tax bureau.'],
        'payroll_deductions' => [null, '1703', 'Other deductions from salaries, e.g. staff advances recovered.'],
        'salary_payment' => ['2402', null, 'Salaries paid to the staff.'],
        // Claims (spec §5.8): what MASDAR gives back to a client, and claims back from a factory.
        'credit_note' => ['4102', '1401', 'A credit note to a client: what the client owes goes down.'],
        'debit_note' => ['2201', '5102', 'A debit note on a supplier: what MASDAR owes the factory goes down.'],
        // Batch C (spec §5.8).
        'fx_revaluation' => ['7101', '7101', 'Exchange revaluation at a month end: a loss is debited, a gain credited.'],
        'expense_claim' => [null, '2405', 'A staff expense claim, approved: each line\'s category account, owed to the claimant.'],
        'claim_payment' => ['2405', null, 'An approved expense claim paid back to the claimant.'],
        'depreciation' => ['6601', '1802', 'A month\'s depreciation of the fixed assets.'],
        'opening_balance' => [null, '3901', 'Opening balances: the other side of each balance brought in.'],
        'service_invoice' => ['1401', null, 'A service invoice to a client (commission, inspection, freight recharged): each line credits its kind\'s income account.'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::LOOKUPS as $list => $rows) {
                foreach ($rows as $i => $row) {
                    Lookup::firstOrCreate(['list' => $list, 'code' => $row[0]], [
                        'name' => $row[1], 'name_ar' => $row[2], 'name_zh' => $row[3],
                        'meta' => $row[4] ?? [], 'sort' => ($i + 1) * 10, 'active' => true,
                    ]);
                }
            }

            foreach (self::STEPS as $i => [$code, $en, $ar, $zh, $kind, $file, $due]) {
                OrderStepDefinition::firstOrCreate(['code' => $code], [
                    'name' => $en, 'name_ar' => $ar, 'name_zh' => $zh, 'kind' => $kind,
                    'requires_attachment' => $file, 'due_offset_days' => $due, 'sort' => ($i + 1) * 10, 'active' => true,
                ]);
            }

            foreach (self::ACCOUNTS as $i => [$code, $en, $ar, $zh, $type, $parent, $currency]) {
                Account::firstOrCreate(['code' => $code], [
                    'name' => $en, 'name_ar' => $ar, 'name_zh' => $zh, 'type' => $type,
                    'parent_id' => $parent ? Account::where('code', $parent)->value('id') : null,
                    'is_postable' => strlen($code) > 2, 'currency' => $currency, 'sort' => $i, 'active' => true,
                ]);
            }

            foreach (self::POSTING_RULES as $event => [$debit, $credit, $description]) {
                PostingRule::firstOrCreate(['event' => $event], [
                    'debit_account_id' => $debit ? Account::where('code', $debit)->value('id') : null,
                    'credit_account_id' => $credit ? Account::where('code', $credit)->value('id') : null,
                    'description' => $description,
                ]);
            }
        });
    }
}
