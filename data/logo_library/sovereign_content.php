<?php
/**
 * Curated EN + AR summaries for Omani sovereign / ministerial / regulatory
 * entities. Applied to om_companies.summary_en + summary_ar via
 * scripts/apply-sovereign-content.php.
 *
 * Keyed by the canonical name_en used by the import pipeline. Lookup is
 * case-insensitive + "ministry of" normalized. Each entry is:
 *   ['en' => '...', 'ar' => '...', 'type' => 'ministry' | 'authority' | 'sovereign' | 'soe']
 *
 * Content is factual and scoped to 70-130 words EN / 55-100 words AR.
 * No marketing fluff, no AI filler. Accuracy > embellishment, if a fact
 * isn't certain, it's omitted.
 *
 * SEO intent: each summary targets "{entity} Oman", "{entity} mandate",
 * "what does {entity} do" long-tail queries.
 */

return [

    // ─── Sovereign funds / authorities ──────────────────────────────────

    'Oman Investment Authority' => [
        'type' => 'sovereign',
        'en' => "Oman Investment Authority (OIA) is the sovereign wealth fund of the Sultanate of Oman, established by Royal Decree in June 2020 through the merger of the State General Reserve Fund and the Oman Investment Fund. OIA manages the government's commercial assets at home and abroad, spanning energy (OQ), logistics (Asyad), telecommunications (Omantel), financial services, real estate, and emerging sectors. It operates under direct oversight of the Council of Ministers with a mandate to deliver risk-adjusted returns and support Oman Vision 2040. Headquartered in Muscat's Muscat Business District (MBD).",
        'ar' => "جهاز الاستثمار العُماني هو الصندوق السيادي لسلطنة عُمان، تأسس بموجب مرسوم سلطاني في يونيو 2020 بدمج صندوق الاحتياطي العام للدولة وصندوق عُمان الاستثماري. يدير الجهاز الأصول التجارية للحكومة محلياً ودولياً، وتشمل محفظته قطاعات الطاقة (OQ) والخدمات اللوجستية (أسياد) والاتصالات (عُمانتل) والخدمات المالية والعقارات والقطاعات الناشئة. مقره الرئيسي في منطقة الأعمال المركزية بمسقط ويرفع تقاريره لمجلس الوزراء.",
    ],

    'Board of Directors of the Central Bank of Oman' => [
        'type' => 'authority',
        'en' => "The Central Bank of Oman (CBO) is the Sultanate's monetary authority and banking regulator, established in 1974. It issues the Omani Rial, manages foreign reserves, licenses commercial and Islamic banks, and oversees the payment systems. The CBO Board of Directors is chaired under Royal Decree and sets monetary policy, financial stability measures, and the regulatory framework for licensed financial institutions. The CBO operates the Mobile Payment Clearing and Settlement System (MPCSS) and the Oman Credit and Financial Information Center (Mala'a). Headquartered in Ruwi, Muscat.",
        'ar' => "البنك المركزي العُماني هو السلطة النقدية والرقابية للقطاع المصرفي في سلطنة عُمان، تأسس عام 1974. يُصدر الريال العُماني، ويدير الاحتياطيات الأجنبية، ويُرخّص البنوك التجارية والإسلامية، ويشرف على نظم المدفوعات. يرأس مجلس إدارة البنك المركزي بمرسوم سلطاني ويضع السياسة النقدية وإجراءات الاستقرار المالي والإطار الرقابي للمؤسسات المالية. مقره الرئيسي في روي بمسقط.",
    ],

    'Tax Authority' => [
        'type' => 'authority',
        'en' => "The Tax Authority is Oman's national tax administration, established in 2020 by Royal Decree replacing the former Secretariat General for Taxation. It administers corporate income tax, Value Added Tax (VAT, introduced April 2021 at 5%), excise tax on harmful goods, and manages Oman's double-taxation treaty network. The Authority operates a fully digital filing and payment portal (tms.taxoman.gov.om), handles audits and appeals, and publishes guidance for residents, foreign investors, and free-zone entities. Headquartered in Muscat.",
        'ar' => "جهاز الضرائب هو الإدارة الضريبية الوطنية في سلطنة عُمان، تأسس عام 2020 بمرسوم سلطاني ليحل محل الأمانة العامة للضرائب. يدير ضريبة دخل الشركات، وضريبة القيمة المضافة (المُطبّقة منذ أبريل 2021 بنسبة 5%)، والضريبة الانتقائية على السلع الضارة، ويتولى شبكة اتفاقيات تجنّب الازدواج الضريبي. يدير الجهاز بوابة إلكترونية متكاملة للإقرارات والدفع. مقره الرئيسي في مسقط.",
    ],

    'State Financial and Administrative Audit Authority' => [
        'type' => 'authority',
        'en' => "The State Financial and Administrative Audit Authority (SAI Oman) is the Sultanate's supreme audit institution, established in 1974. It audits government ministries, state-owned enterprises, and public funds; investigates financial and administrative irregularities; and reports directly to His Majesty the Sultan and the Council of Ministers. It is a member of INTOSAI and ARABOSAI and publishes annual reports on the performance of public-sector entities. Headquartered in Muscat.",
        'ar' => "جهاز الرقابة المالية والإدارية للدولة هو المؤسسة العُليا للرقابة في سلطنة عُمان، تأسس عام 1974. يُدقّق الوزارات والمؤسسات الحكومية والشركات المملوكة للدولة، ويحقق في المخالفات المالية والإدارية، ويرفع تقاريره مباشرة إلى جلالة السلطان ومجلس الوزراء. عضو في المنظمة الدولية للأجهزة العُليا للرقابة (إنتوساي) ومنظمة أرابوساي. مقره الرئيسي في مسقط.",
    ],

    'National Centre for Statistics and Information' => [
        'type' => 'authority',
        'en' => "The National Centre for Statistics and Information (NCSI) is Oman's official statistics body, established by Royal Decree in 2012. It runs the decennial Population and Housing Census, publishes the Monthly Statistical Bulletin and Statistical Year Book, produces the Consumer Price Index, and operates the Data Portal (data.gov.om). NCSI supports Oman Vision 2040 monitoring and provides the statistical base for national policy. Headquartered in Muscat.",
        'ar' => "المركز الوطني للإحصاء والمعلومات هو الجهة الإحصائية الرسمية لسلطنة عُمان، تأسس بمرسوم سلطاني عام 2012. يُجري التعداد العام للسكان والمساكن، ويُصدر النشرة الإحصائية الشهرية والكتاب الإحصائي السنوي، ويُنتج مؤشر أسعار المستهلك، ويُشغّل بوابة البيانات المفتوحة. يدعم المركز متابعة رؤية عُمان 2040. مقره الرئيسي في مسقط.",
    ],

    'Oman Vision 2040 Implementation Follow-Up Unit' => [
        'type' => 'authority',
        'en' => "The Oman Vision 2040 Implementation Follow-Up Unit is the executive body tasked with tracking and coordinating the national vision's strategic objectives across economic diversification, knowledge-based economy, fiscal sustainability, and human capital development. Launched in 2020 following the approval of Oman Vision 2040 by Royal Decree, the Unit works with ministries and state entities to align programs with vision pillars and publishes performance dashboards. Headquartered in Muscat.",
        'ar' => "وحدة متابعة تنفيذ رؤية عُمان 2040 هي الجهة التنفيذية المنوط بها تتبّع الأهداف الاستراتيجية للرؤية الوطنية عبر التنويع الاقتصادي، والاقتصاد القائم على المعرفة، والاستدامة المالية، وتنمية رأس المال البشري. تأسست عام 2020 بعد اعتماد رؤية عُمان 2040 بمرسوم سلطاني. مقرها الرئيسي في مسقط.",
    ],

    'Oman Medical Specialty Board' => [
        'type' => 'authority',
        'en' => "The Oman Medical Specialty Board (OMSB) is the national authority for postgraduate medical education and specialty certification in Oman, established in 2006. OMSB runs residency and fellowship programs across dozens of specialties, administers board examinations, accredits training institutions, and maintains the register of certified Omani specialists. It works closely with the Ministry of Health, Sultan Qaboos University, and international accreditation bodies (ACGME-I, Royal Colleges). Headquartered in Muscat.",
        'ar' => "المجلس العُماني للاختصاصات الطبية هو الجهة الوطنية للتعليم الطبي العليا ومنح شهادات التخصصات في سلطنة عُمان، تأسس عام 2006. يدير برامج الإقامة والزمالة في عشرات التخصصات، ويُنظّم الامتحانات، ويعتمد مؤسسات التدريب، ويحتفظ بسجل الأخصائيين العُمانيين. مقره الرئيسي في مسقط.",
    ],

    'The General Secretariat for the National Celebrations' => [
        'type' => 'authority',
        'en' => "The General Secretariat for the National Celebrations coordinates the Sultanate of Oman's official National Day observances and other state-level celebrations. It organizes the annual National Day parade, cultural events, and civic programs held across the governorates, coordinating with ministries, the Royal Oman Police, and civic groups. Headquartered in Muscat.",
        'ar' => "الأمانة العامة للاحتفالات الوطنية تنسّق الاحتفالات الرسمية باليوم الوطني لسلطنة عُمان وسائر المناسبات الوطنية الكبرى. تُنظّم العرض العسكري السنوي للعيد الوطني والفعاليات الثقافية والبرامج المدنية عبر محافظات السلطنة بالتنسيق مع الوزارات وشرطة عُمان السلطانية. مقرها الرئيسي في مسقط.",
    ],

    // ─── Ministries (alphabetical) ──────────────────────────────────────

    'Ministry of Agriculture, Fisheries and Water Resources' => [
        'type' => 'ministry',
        'en' => "The Ministry of Agriculture, Fisheries and Water Resources oversees Oman's food security agenda, fisheries management, agricultural extension, veterinary services, and water-resource planning, including Oman's traditional aflaj irrigation system, aquifer management, and desalination coordination. It licenses commercial fishing, runs agricultural research stations, and supports the development of Oman's seafood export sector (notably shrimp, kingfish, and tuna). Headquartered in Muscat.",
        'ar' => "وزارة الثروة الزراعية والسمكية وموارد المياه تُشرف على منظومة الأمن الغذائي، وإدارة الثروة السمكية، والإرشاد الزراعي، والخدمات البيطرية، وتخطيط الموارد المائية, بما فيها نظام الأفلاج التقليدي وإدارة طبقات المياه الجوفية. تُرخّص الصيد التجاري وتدير مراكز البحث الزراعي وتدعم قطاع الصادرات السمكية. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Commerce, Industry and Investment Promotion' => [
        'type' => 'ministry',
        'en' => "The Ministry of Commerce, Industry and Investment Promotion (MoCIIP) is Oman's lead ministry for private-sector development, commercial registration, industrial policy, and foreign investment attraction. It maintains the Sultanate's commercial register (accessible via business.gov.om), regulates consumer protection and product safety, and operates Invest Oman as the national investment promotion agency. MoCIIP works with ministries, free zones, and SEZs to streamline business setup. Headquartered in Muscat.",
        'ar' => "وزارة التجارة والصناعة وترويج الاستثمار هي الوزارة المُكلَّفة بتنمية القطاع الخاص والسجل التجاري والسياسة الصناعية واستقطاب الاستثمار الأجنبي. تُدير السجل التجاري للسلطنة (business.gov.om)، وتُنظّم حماية المستهلك وسلامة المنتجات، وتُشغّل جهاز الاستثمار (Invest Oman). مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Culture, Sports and Youth' => [
        'type' => 'ministry',
        'en' => "The Ministry of Culture, Sports and Youth oversees Oman's cultural heritage, arts, sports federations, youth programs, and scouting. It operates the Royal Opera House, the National Museum, public libraries, and supports the Oman Football Association, Oman Olympic Committee, and youth centers across the governorates. It administers the Royal Cultural Creativity Awards and the Youth Trust Fund. Headquartered in Muscat.",
        'ar' => "وزارة الثقافة والرياضة والشباب تُشرف على الموروث الثقافي والفنون واتحادات الرياضة وبرامج الشباب والحركة الكشفية. تُدير دار الأوبرا السلطانية والمتحف الوطني والمكتبات العامة، وتدعم الاتحاد العُماني لكرة القدم واللجنة الأولمبية العُمانية ومراكز الشباب في المحافظات. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Defence' => [
        'type' => 'ministry',
        'en' => "The Ministry of Defence administers the Sultan's Armed Forces, the Royal Army of Oman, Royal Navy of Oman, Royal Air Force of Oman, and Sultan's Special Force, handling procurement, logistics, personnel policy, strategic planning, and coordination with allied forces. It operates training academies including the Sultan Qaboos Military Academy and the Command and Staff College. Headquartered in Muscat.",
        'ar' => "وزارة الدفاع تُشرف على قوات السلطان المسلحة (الجيش السلطاني العُماني، البحرية السلطانية العُمانية، سلاح الجو السلطاني العُماني، قوة السلطان الخاصة) وتتولى التموين واللوجستيات وسياسات الأفراد والتخطيط الاستراتيجي. تدير أكاديميات التدريب العسكرية. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Economy' => [
        'type' => 'ministry',
        'en' => "The Ministry of Economy is responsible for Oman's macroeconomic policy, national planning, and the implementation of the Five-Year Development Plans. Established in its current form in 2020 following a government restructure, it consolidates responsibilities previously spread across the Supreme Council for Planning and the Ministry of Finance. Key focus areas: economic diversification, Oman Vision 2040 alignment, and SME sector growth. Headquartered in Muscat.",
        'ar' => "وزارة الاقتصاد مسؤولة عن السياسة الاقتصادية الكلية والتخطيط الوطني وتنفيذ الخطط الخمسية للتنمية. أُنشئت بصيغتها الحالية عام 2020 بعد إعادة هيكلة حكومية. تركّز على التنويع الاقتصادي ومواءمة رؤية عُمان 2040 وتنمية قطاع المؤسسات الصغيرة والمتوسطة. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Education' => [
        'type' => 'ministry',
        'en' => "The Ministry of Education oversees pre-primary, primary, and secondary education across Oman, including the public school system, curriculum development, teacher training, and the International Baccalaureate-aligned diploma track in select schools. It manages thousands of government schools in the 11 governorates and supervises private schools. Headquartered in Muscat.",
        'ar' => "وزارة التربية والتعليم تُشرف على التعليم قبل المدرسي والأساسي والثانوي في سلطنة عُمان, نظام المدارس الحكومية وتطوير المناهج وتدريب المعلمين. تدير آلاف المدارس الحكومية في المحافظات الإحدى عشرة وتشرف على المدارس الخاصة. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Endowments and Religious Affairs' => [
        'type' => 'ministry',
        'en' => "The Ministry of Endowments and Religious Affairs manages Oman's mosque network, Islamic endowments (awqaf), religious publications, pilgrimage logistics (Hajj and Umrah), and interfaith dialogue. It operates the Sultan Qaboos Grand Mosque Information Center and publishes official religious rulings through the Grand Mufti's office. Headquartered in Muscat.",
        'ar' => "وزارة الأوقاف والشؤون الدينية تُشرف على المساجد والأوقاف الإسلامية والمطبوعات الدينية وتنظيم الحج والعمرة وحوار الأديان. تُدير مركز المعلومات بجامع السلطان قابوس الأكبر وتُصدر الفتاوى الرسمية عبر مكتب المفتي العام. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Energy and Minerals' => [
        'type' => 'ministry',
        'en' => "The Ministry of Energy and Minerals is Oman's lead ministry for oil, gas, renewable energy, and mining policy. It oversees concession management for upstream exploration, LNG and condensate exports, renewable targets (including utility-scale solar at Ibri and wind at Dhofar), and mining of copper, chromite, and industrial minerals. Works closely with OQ, PDO, and the Oman Power and Water Procurement Company. Headquartered in Muscat.",
        'ar' => "وزارة الطاقة والمعادن مسؤولة عن السياسة النفطية والغازية والطاقة المتجددة والتعدين في سلطنة عُمان. تُشرف على عقود الامتياز للاستكشاف، وصادرات الغاز الطبيعي المسال، وأهداف الطاقة المتجددة (مشاريع الطاقة الشمسية في إبري وطاقة الرياح في ظفار)، وتعدين النحاس والكروم. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Finance' => [
        'type' => 'ministry',
        'en' => "The Ministry of Finance is responsible for Oman's public finances, preparing the annual state budget, managing public debt and sovereign borrowing, administering government accounts, and overseeing financial relations with multilateral institutions (IMF, World Bank, GCC Development Fund). It coordinates with the Central Bank of Oman on fiscal-monetary alignment and reports on quarterly revenue/expenditure performance. Headquartered in Muscat.",
        'ar' => "وزارة المالية مسؤولة عن المالية العامة لسلطنة عُمان, إعداد الميزانية السنوية للدولة، وإدارة الدين العام والاقتراض السيادي، وإدارة الحسابات الحكومية، والإشراف على العلاقات المالية مع المؤسسات الدولية (صندوق النقد الدولي، البنك الدولي). مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Foreign Affairs' => [
        'type' => 'ministry',
        'en' => "The Ministry of Foreign Affairs conducts Oman's diplomatic relations, consular services, and multilateral engagements. It operates Oman's embassies and consulates worldwide, manages visa and legalization services, and represents Oman in the GCC, Arab League, UN, OPEC+ observers, and international forums. Historically anchored in Oman's tradition of balanced, quiet diplomacy. Headquartered in Muscat.",
        'ar' => "وزارة الخارجية تُدير العلاقات الدبلوماسية لسلطنة عُمان والخدمات القنصلية والمشاركات متعددة الأطراف. تُشغّل السفارات والقنصليات في الخارج، وتُدير خدمات التأشيرات والتصديقات، وتُمثّل عُمان في مجلس التعاون الخليجي وجامعة الدول العربية والأمم المتحدة. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Health' => [
        'type' => 'ministry',
        'en' => "The Ministry of Health runs Oman's public healthcare system, hospitals, primary care centers, preventive health programs, disease surveillance, and pharmaceutical regulation. It operates major referral hospitals including the Royal Hospital, the Khoula Hospital, and regional tertiary facilities across the governorates. The Ministry handled Oman's COVID-19 response and vaccination rollout and runs national programs for maternal health, diabetes, and cardiovascular disease. Headquartered in Muscat.",
        'ar' => "وزارة الصحة تُدير منظومة الرعاية الصحية الحكومية في سلطنة عُمان, المستشفيات ومراكز الرعاية الأولية وبرامج الصحة الوقائية ومراقبة الأمراض وتنظيم الأدوية. تُشغّل المستشفيات المرجعية الكبرى كالمستشفى السلطاني ومستشفى خولة والمستشفيات المرجعية في المحافظات. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Heritage and Tourism' => [
        'type' => 'ministry',
        'en' => "The Ministry of Heritage and Tourism oversees Oman's UNESCO World Heritage Sites (Bahla Fort, Bat and Al-Ayn archaeological sites, Aflaj Irrigation Systems, Land of Frankincense, Ancient City of Qalhat), tourism licensing, hotel classification, and cultural-heritage preservation. It runs Oman Tourism Development Company (Omran) linkages and markets the Sultanate through Experience Oman. Headquartered in Muscat.",
        'ar' => "وزارة التراث والسياحة تُشرف على مواقع التراث العالمي في عُمان (قلعة بهلاء، مواقع بات والعين الأثرية، أنظمة الأفلاج، أرض اللبان، قلهات الأثرية) وتُرخّص السياحة وتُصنّف الفنادق وتُحافظ على التراث الثقافي. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Higher Education, Research and Innovation' => [
        'type' => 'ministry',
        'en' => "The Ministry of Higher Education, Research and Innovation (MoHERI) supervises Oman's universities, colleges of technology, and research institutions, including Sultan Qaboos University, the University of Technology and Applied Sciences, and private universities. It administers the national scholarship program, manages research funding through The Research Council legacy portfolio, and sets innovation policy aligned with Oman Vision 2040. Headquartered in Muscat.",
        'ar' => "وزارة التعليم العالي والبحث العلمي والابتكار تُشرف على الجامعات وكليات التقنية ومؤسسات البحث في سلطنة عُمان, بما فيها جامعة السلطان قابوس وجامعة التقنية والعلوم التطبيقية والجامعات الخاصة. تدير برنامج البعثات الوطني وتموّل البحث العلمي. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Information' => [
        'type' => 'ministry',
        'en' => "The Ministry of Information is responsible for Oman's public media, press licensing, broadcasting policy, and government communications. It oversees Oman TV, Oman Radio, the Oman News Agency (ONA), and licensing for private media outlets. Historically anchored in Oman's reputation for measured, non-sensational reporting. Headquartered in Muscat.",
        'ar' => "وزارة الإعلام مسؤولة عن الإعلام الرسمي في سلطنة عُمان وترخيص الصحافة وسياسة الإذاعة والاتصال الحكومي. تُشرف على تلفزيون وإذاعة سلطنة عُمان ووكالة الأنباء العُمانية وترخيص المؤسسات الإعلامية الخاصة. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Interior' => [
        'type' => 'ministry',
        'en' => "The Ministry of Interior is responsible for Oman's civil administration at the governorate level, including coordinating with walis (governors) and their deputies, elections logistics for the Majlis Al-Shura and municipal councils, and tribal and community affairs. It works alongside but separate from the Royal Oman Police. Headquartered in Muscat.",
        'ar' => "وزارة الداخلية مسؤولة عن الإدارة المدنية على مستوى المحافظات في سلطنة عُمان, التنسيق مع الولاة ونوابهم، وتنظيم انتخابات مجلس الشورى والمجالس البلدية، والشؤون القبلية والمجتمعية. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Justice and Legal Affairs' => [
        'type' => 'ministry',
        'en' => "The Ministry of Justice and Legal Affairs oversees Oman's judicial administration, court infrastructure, notary public services, and legal drafting for government entities. It supports the judiciary (which is independent under the Basic Law), manages court buildings across the governorates, and regulates the notary profession. Headquartered in Muscat.",
        'ar' => "وزارة العدل والشؤون القانونية تُشرف على الإدارة القضائية في سلطنة عُمان، والبنية التحتية للمحاكم، وخدمات الكتاب بالعدل، والصياغة القانونية للجهات الحكومية. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Labour' => [
        'type' => 'ministry',
        'en' => "The Ministry of Labour administers Oman's labour market policy, employment of nationals (Omanization), work permits for expatriate workers, labour dispute resolution, vocational training, and social protection (Social Protection Fund oversight). It publishes Omanization percentages by sector, runs the Tanfeedh-aligned employment initiatives, and manages the Sanad service centers. Headquartered in Muscat.",
        'ar' => "وزارة العمل تُدير سياسة سوق العمل في سلطنة عُمان، والتعمين، وتصاريح العمل للوافدين، وفض المنازعات العُمالية، والتدريب المهني، والإشراف على صندوق الحماية الاجتماعية. تُشغّل مراكز خدمات سند. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Housing and Urban Planning' => [
        'type' => 'ministry',
        'en' => "The Ministry of Housing and Urban Planning oversees Oman's urban planning, land registry, housing policy, and social housing programs. It administers land grants for citizens, manages the residential-plot subdivision program in Muscat and the governorates, issues building permits in coordination with municipalities, and directs master planning for new urban developments. Headquartered in Muscat.",
        'ar' => "وزارة الإسكان والتخطيط العمراني تُشرف على التخطيط العمراني والسجل العقاري وسياسة الإسكان وبرامج الإسكان الاجتماعي في سلطنة عُمان. تدير منح الأراضي للمواطنين وبرامج تقسيم الأراضي السكنية وتصاريح البناء. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Social Development' => [
        'type' => 'ministry',
        'en' => "The Ministry of Social Development oversees Oman's social welfare programs, family policy, orphan care, elderly services, disability inclusion, and civil-society sector licensing (associations, clubs). It administers the social security system (partly merged into the Social Protection Fund), runs the Sanad centers in cooperation with the Ministry of Labour, and supervises family guidance centers. Headquartered in Muscat.",
        'ar' => "وزارة التنمية الاجتماعية تُشرف على برامج الرعاية الاجتماعية في سلطنة عُمان، وسياسة الأسرة، ورعاية الأيتام، وخدمات كبار السن، ودمج ذوي الإعاقة، وترخيص منظمات المجتمع المدني. مقرها الرئيسي في مسقط.",
    ],

    'Ministry of Transport, Communications and Information Technology' => [
        'type' => 'ministry',
        'en' => "The Ministry of Transport, Communications and Information Technology is Oman's lead ministry for aviation, ports, roads, public transport, telecommunications, postal services, and digital government. It oversees Oman Airports, Asyad's maritime portfolio, the national road network, Mwasalat public transport, and the Telecommunications Regulatory Authority. Key focus: port-led diversification (Sohar, Duqm, Salalah) and Oman's digital transformation agenda. Headquartered in Muscat.",
        'ar' => "وزارة النقل والاتصالات وتقنية المعلومات هي الوزارة المُشرفة على الطيران والموانئ والطرق والنقل العام والاتصالات والبريد والحكومة الرقمية في سلطنة عُمان. تشمل مطارات عُمان ومحفظة أسياد البحرية والشبكة الوطنية للطرق ومواصلات وهيئة تنظيم الاتصالات. مقرها الرئيسي في مسقط.",
    ],

    // ─── Semi-government corporates ────────────────────────────────────

    'ASYAD Group' => [
        'type' => 'soe',
        'en' => "ASYAD Group is Oman's integrated logistics holding, wholly owned by the Oman Investment Authority. It consolidates the Sultanate's port, free-zone, shipping, dry-port, and freight operations, including Port of Sohar, Port of Duqm, Port of Salalah (through a partnership), Oman Shipping Company, Oman Dry Dock, and Asyad Shipping. Established in 2016 to create a single platform aligned with Oman's strategy to become a global logistics hub. Headquartered in Muscat Business District.",
        'ar' => "مجموعة أسياد هي الشركة القابضة المتكاملة للخدمات اللوجستية في سلطنة عُمان، ومملوكة بالكامل لجهاز الاستثمار العُماني. تُوحّد محفظة الموانئ والمناطق الحرة والشحن والموانئ الجافة في السلطنة, تشمل ميناء صحار وميناء الدقم وميناء صلالة (بالشراكة) والشركة العُمانية للشحن البحري وحوض عُمان الجاف. تأسست عام 2016. مقرها الرئيسي في منطقة الأعمال المركزية بمسقط.",
    ],

    'Sohar Port and Freezone' => [
        'type' => 'soe',
        'en' => "Sohar Port and Freezone is one of the world's fastest-growing deep-water port complexes, located on Oman's Al-Batinah North coast approximately 200 km northwest of Muscat and outside the Strait of Hormuz. Operated jointly by ASYAD Group (Oman) and the Port of Rotterdam (Netherlands), it handles over 50 million tonnes of cargo annually across containers, dry bulk, liquids, and break-bulk. The adjacent freezone hosts petrochemical, metals, food-processing, and logistics investors. Based in Sohar, Al Batinah North.",
        'ar' => "ميناء صحار والمنطقة الحرة هو مجمع موانئ المياه العميقة الأسرع نمواً في العالم، يقع على ساحل شمال الباطنة شمال غرب مسقط خارج مضيق هرمز. يُدار بشراكة بين مجموعة أسياد العُمانية وميناء روتردام الهولندي. يُعالج أكثر من 50 مليون طن من البضائع سنوياً عبر الحاويات والبضائع الجافة والسوائل. تضم المنطقة الحرة مستثمرين في البتروكيماويات والمعادن والأغذية. يقع في صحار، شمال الباطنة.",
    ],

    'Oman Chamber of Commerce & Industry' => [
        'type' => 'authority',
        'en' => "The Oman Chamber of Commerce and Industry (OCCI) is the national business federation representing Omani private-sector enterprises, established in 1973. It issues certificates of origin, mediates commercial disputes, organizes trade missions and sector-specific forums, and represents business interests in government consultations. OCCI operates branches in every governorate and publishes the Oman Business Directory. Headquartered in Muscat with branches across the governorates.",
        'ar' => "غرفة تجارة وصناعة عُمان هي الاتحاد الوطني للقطاع الخاص العُماني، تأسست عام 1973. تُصدر شهادات المنشأ، وتتوسّط في المنازعات التجارية، وتُنظّم البعثات التجارية والمنتديات القطاعية، وتُمثّل مصالح الأعمال في المشاورات الحكومية. لها فروع في جميع المحافظات ومقرها الرئيسي في مسقط.",
    ],
];
