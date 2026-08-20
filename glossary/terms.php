<?php
/**
 * The glossary corpus. ONE definition per term, in one place.
 *
 * r328. Structured as data rather than six near-identical page files so that
 * a term's definition cannot drift between the hub card, the term page, the
 * DefinedTerm schema and any future surface that wants to quote it. The hub
 * and glossary/term.php both read from here; nothing else defines a term.
 *
 * Shape per term:
 *   title        EN display name, also DefinedTerm.name
 *   ar_title     Arabic display name, also DefinedTerm.alternateName.
 *                Real Omani commercial usage, not a transliteration of the
 *                English. Where a term genuinely has no settled Arabic form,
 *                the Arabic name keeps the Latin acronym in place, which is
 *                what practitioners here actually write.
 *   short        The definition proper. First sentence answers "what is X"
 *                in one sentence, because that is the unit a featured snippet
 *                and an AI overview extract. Keep it under ~60 words.
 *   ar_short     The same definition in Arabic. A real translation, written
 *                to be read, not run through a machine and pasted.
 *   also         Alternative names people search for.
 *   sections     [heading, [paragraph, ...]] body copy.
 *   faq          [[question, answer], ...]
 *   related      slugs of sibling terms.
 */
return [

'digital-business-card' => [
    'title'    => 'Digital business card',
    'ar_title' => 'بطاقة العمل الرقمية',
    'also'     => ['electronic business card', 'e-business card', 'smart business card'],
    'short'    => 'A digital business card is a web page holding one person\'s contact details, shared instantly by QR code, link, tap or message. The recipient taps once to save the details into their phone\'s address book. Because it is a live page rather than printed ink, updating it updates every copy already shared.',
    'ar_short' => 'بطاقة العمل الرقمية هي صفحة إلكترونية تحتوي على بيانات التواصل الخاصة بشخص واحد، تُشارَك فوراً عبر رمز الاستجابة السريعة أو رابط أو لمسة أو رسالة. يضغط المستلم مرة واحدة لحفظ البيانات في دفتر جهات الاتصال في هاتفه. ولأنها صفحة حيّة وليست حبراً مطبوعاً، فإن تحديثها يحدّث كل نسخة سبقت مشاركتها.',
    'sections' => [
        ['What it replaces, and what it does not', [
            'A digital business card replaces the act of transcribing a paper card, not the card itself. The moment of exchange stays: you still meet someone, you still hand something over. What disappears is the gap between receiving a card and having the contact in your phone, which is where most paper cards die.',
            'It does not necessarily replace printed cards. In markets where a physical card carries formal weight, including much of the Gulf, the common arrangement is a printed card with a QR code on the back pointing at the digital one. The paper does the ceremony, the QR does the data entry.',
        ]],
        ['How the exchange works', [
            'The card lives at a web address. That address can be reached four ways: by scanning a QR code, by opening a link, by tapping a phone against an NFC card, or by opening a pass stored in Apple Wallet or Google Wallet. All four routes land on the same page.',
            'Once the page is open, a "Save contact" button hands the phone a vCard file, the standard contact format every phone already understands. The recipient never installs an app and never creates an account.',
        ]],
        ['Why "live" is the whole point', [
            'A printed card is a photograph of your details on the day it was printed. Change your mobile number and every card in circulation becomes quietly wrong, with no way to correct it.',
            'A digital card is the details themselves. Change the number once and every share updates, including QR codes printed on brochures, vehicle livery or exhibition stands months earlier. For an individual that is convenient. For an organisation with hundreds of staff it is the difference between a contact channel you control and thousands of assertions about your company that you cannot reach.',
        ]],
        ['What organisations get that individuals do not', [
            'At company scale, the card stops being a personal accessory and becomes an asset. The template is approved once by whoever owns the brand, so two hundred people cannot invent two hundred layouts. Employees own their own data and nothing else.',
            'When someone leaves, the company keeps the URL, the QR code and the history, and can reassign them. On platforms where the card is welded to an individual\'s account, a departure destroys the asset.',
        ]],
    ],
    'faq' => [
        ['Is a digital business card the same as a QR code?',
         'No. The QR code is one way to open the card, not the card itself. The same profile can be reached by link, by NFC tap, by wallet pass or by message. Replacing the QR image does not change the card.'],
        ['Do digital business cards need an app?',
         'Not for the person receiving one. It opens in the normal phone browser. An app is only involved if you want to scan and file other people\'s paper cards.'],
        ['Are digital business cards free?',
         'Some are. The Cardify web platform is free for unlimited employees with no credit card required. Other platforms charge per user per month.'],
    ],
    'related' => ['contactless-business-card', 'vcard', 'qr-vcard'],
],

'nfc' => [
    'title'    => 'NFC',
    'ar_title' => 'الاتصال قريب المدى (NFC)',
    'also'     => ['Near Field Communication', 'tap to share', 'NFC tag'],
    'short'    => 'NFC, or Near Field Communication, is a short-range radio standard that lets two devices exchange a small amount of data when held within a few centimetres of each other. In a business card, an NFC chip stores one web address and hands it to any phone that touches it. The chip needs no battery.',
    'ar_short' => 'الاتصال قريب المدى (NFC) معيار لاسلكي قصير المدى يتيح لجهازين تبادل قدر صغير من البيانات عند تقريب أحدهما من الآخر لبضعة سنتيمترات. في بطاقة العمل، تخزّن شريحة NFC عنوان صفحة واحداً وتسلّمه لأي هاتف يلامسها. ولا تحتاج الشريحة إلى بطارية.',
    'sections' => [
        ['Where the power comes from', [
            'The chip inside an NFC business card is passive. It contains no battery and does nothing at all until a phone comes close enough, at which point the phone\'s own radio field induces just enough current in the chip\'s antenna to power it for the fraction of a second it takes to answer.',
            'This is why an NFC card works after five years in a wallet, and why it cannot be drained, switched off, or left uncharged before a meeting.',
        ]],
        ['What it can and cannot carry', [
            'A business-card NFC chip stores a URL and nothing else. It holds no name, no phone number and no personal data, because the details live on the web page the URL points at. That indirection is what allows the card to stay correct when the details change.',
            'It also carries no payment credential and cannot read anything off the phone that taps it. The exchange is one-directional: the phone asks, the chip answers with a link, and the phone decides whether to open it.',
        ]],
        ['The range is the security model', [
            'NFC works over roughly two to four centimetres. That is not a limitation to be engineered around, it is the security property: a tag cannot be read from across a room, from a passing car, or by someone standing behind you in a queue. Physical proximity is the consent.',
        ]],
        ['Which phones read it', [
            'Every iPhone from the iPhone XR and XS onward reads NFC tags in the background, with the screen simply on and unlocked and no app open. Older iPhone 7, 8 and X models can read tags through the NFC Tag Reader in Control Centre, which works but requires the owner to open it first.',
            'Most Android phones sold in the past decade include NFC and read tags automatically once NFC is enabled in settings. Many budget handsets have no NFC hardware at all, which is why a well-made NFC business card also carries a printed QR code.',
        ]],
    ],
    'faq' => [
        ['Is NFC safe?',
         'Yes, for this use. A business-card tag is read-only in normal use, stores only a web address, carries no payment credential, and can only be read from a couple of centimetres away.'],
        ['Does NFC need Bluetooth or Wi-Fi?',
         'No. NFC is its own radio standard and works with both switched off. Opening the web page the tag points at does need a connection.'],
        ['Can an NFC card be reprogrammed?',
         'Cardify NFC cards can be re-pointed at a different profile after manufacture, so a card can be reassigned rather than discarded when someone changes role or leaves.'],
    ],
    'related' => ['contactless-business-card', 'digital-business-card'],
],

'vcard' => [
    'title'    => 'vCard',
    'ar_title' => 'ملف جهة الاتصال (vCard)',
    'also'     => ['.vcf file', 'VCF', 'contact card file', 'electronic contact file'],
    'short'    => 'A vCard is the standard file format for a contact record, saved with a .vcf extension. It stores a person\'s name, organisation, job title, phone numbers, email addresses and photo in plain text that every contacts app on every platform can read. Saving a digital business card to a phone means handing it a vCard.',
    'ar_short' => 'ملف vCard هو الصيغة القياسية لسجل جهة الاتصال، ويُحفَظ بامتداد ‎.vcf‎. يخزّن اسم الشخص وجهة عمله ومسماه الوظيفي وأرقام هواتفه وبريده الإلكتروني وصورته في نص بسيط يمكن لأي تطبيق جهات اتصال على أي نظام قراءته. وحفظ بطاقة عمل رقمية في الهاتف يعني في الواقع تسليمه ملف vCard.',
    'sections' => [
        ['Why the format matters more than it sounds', [
            'The vCard is the reason a digital business card works on a stranger\'s phone. It is an open standard that predates the smartphone, and support for it is universal: iOS Contacts, Android Contacts, Outlook, Google Contacts and every CRM worth the name can import a .vcf file without being told how.',
            'That universality is what removes the friction. There is no app to install, no account to create and no compatibility question to ask, because the receiving device already knows what to do with the file.',
        ]],
        ['What it contains', [
            'A vCard is plain text with labelled fields. A minimal one carries a formatted name, an organisation and a telephone number. A full one carries structured given and family names, multiple numbers each labelled work, mobile or fax, several email addresses, a postal address, a job title, a website, a photograph and free-form notes.',
            'It can also carry a name more than once in different scripts, which is how a single saved contact ends up findable by searching either the Arabic or the English spelling of the same person.',
        ]],
        ['Where a vCard falls short', [
            'A vCard is a snapshot. Once it is saved into somebody\'s phone, it is a copy, and changing your details later does not reach into their address book and correct it.',
            'This is the trade-off at the heart of digital business cards. The live page stays correct forever; the saved contact does not. In practice the saved copy is still worth having, because a number in the address book is what makes the phone ring, and re-sharing the card issues a fresh vCard.',
        ]],
    ],
    'faq' => [
        ['What is a .vcf file?',
         'A .vcf file is a vCard, the standard file format for a contact record. Opening one on a phone offers to add the person to the address book.'],
        ['Can a vCard hold an Arabic name?',
         'Yes. vCard supports Unicode, so Arabic names are stored correctly, and a card can carry both an Arabic and an English spelling of the same person.'],
        ['Does saving a vCard update automatically later?',
         'No. A saved vCard is a copy taken at that moment. The live digital business card stays current, but the copy in someone\'s phone does not change on its own.'],
    ],
    'related' => ['qr-vcard', 'digital-business-card'],
],

'qr-vcard' => [
    'title'    => 'QR vCard',
    'ar_title' => 'رمز الاستجابة السريعة لجهة الاتصال',
    'also'     => ['contact QR code', 'vCard QR code', 'QR contact card'],
    'short'    => 'A QR vCard is a QR code that delivers a contact record. There are two kinds: a static one with the contact details encoded directly in the pattern, and a dynamic one encoding a short link to a digital business card that then serves the vCard. Only the dynamic kind can be changed after printing.',
    'ar_short' => 'رمز الاستجابة السريعة لجهة الاتصال هو رمز QR يسلّم سجل جهة اتصال. وله نوعان: ثابت تُشفَّر فيه البيانات مباشرة داخل النمط، وديناميكي يُشفَّر فيه رابط قصير يقود إلى بطاقة عمل رقمية تسلّم بدورها ملف جهة الاتصال. والنوع الديناميكي وحده يمكن تعديله بعد الطباعة.',
    'sections' => [
        ['The distinction that costs money to get wrong', [
            'A static QR vCard has the name, number and email written into the black-and-white pattern itself. Scan it and the phone reads the contact straight out of the image, with no internet involved. It is self-contained, and it is frozen: the pattern is the data, so changing a phone number means a new pattern and a reprint of everything the old one was printed on.',
            'A dynamic QR vCard encodes only a short web address. Scanning it opens a digital business card, which then offers the contact file. The pattern never has to change, because the details are not in it.',
            'Organisations that print QR codes onto anything durable, vehicle livery, exhibition stands, signage, brochure runs, should use dynamic codes. A static code on a five-year sign is a five-year commitment to one mobile number.',
        ]],
        ['Why static codes look denser', [
            'A QR code\'s complexity scales with how much it carries. A full contact record is far more data than a short URL, so a static vCard code has many more modules packed into the same square, which makes it harder to scan when printed small, poorly, or on a curved surface.',
            'A dynamic code carrying a short link is visually sparser and stays readable at smaller sizes, which is why it survives being printed on the back of a business card.',
        ]],
        ['What a dynamic code gives up', [
            'It needs a connection. A static code hands over the contact with no signal at all, which is a genuine advantage in a basement exhibition hall or on a plane.',
            'It also depends on the service behind the link continuing to exist. That is a real consideration when choosing a provider, and a reason to prefer one whose free tier has no expiry to lapse.',
        ]],
    ],
    'faq' => [
        ['Can a QR vCard be changed after printing?',
         'Only a dynamic one. A static QR code contains the contact details inside the pattern, so changing them requires a new code. A dynamic code points at a page you can edit at any time.'],
        ['Does a QR vCard work without internet?',
         'A static one does, because the details are inside the pattern. A dynamic one needs a connection to open the page it points at.'],
        ['Do QR codes expire?',
         'The pattern itself never expires. What can lapse is the service behind a dynamic code, if the provider shuts it down or the plan ends.'],
    ],
    'related' => ['vcard', 'digital-business-card'],
],

'apple-wallet-pass' => [
    'title'    => 'Apple Wallet pass',
    'ar_title' => 'بطاقة محفظة آبل',
    'also'     => ['.pkpass', 'Wallet pass', 'Apple Wallet business card'],
    'short'    => 'An Apple Wallet pass is a small signed file, with a .pkpass extension, that sits in the Wallet app on an iPhone alongside boarding passes and tickets. A business card pass carries the person\'s details and a scannable code, works with no signal, and can be updated remotely by whoever issued it.',
    'ar_short' => 'بطاقة محفظة آبل ملف صغير موقّع رقمياً بامتداد ‎.pkpass‎، يوضَع في تطبيق Wallet على الآيفون إلى جانب بطاقات الصعود للطائرة والتذاكر. وبطاقة العمل بهذه الصيغة تحمل بيانات صاحبها ورمزاً قابلاً للمسح، وتعمل دون اتصال بالإنترنت، ويمكن لجهة إصدارها تحديثها عن بُعد.',
    'sections' => [
        ['Why a wallet pass is not just another copy of the card', [
            'Two properties make it different from a link or a QR image saved to the photo roll. First, it works offline: the pass and its code are stored on the device, so it displays in an exhibition hall basement with no signal, which is precisely where a business card is most needed.',
            'Second, it can be updated after it has been handed out. The issuer can push a change and every installed pass updates itself, which no printed card and no saved screenshot can do.',
        ]],
        ['How it is put together', [
            'A pass is a small bundle containing a description of the fields to display, the images to draw, and a cryptographic signature. The signature is issued against a certificate held by the organisation that made the pass, which is what stops anyone from forging or silently altering one.',
            'That signing requirement is also why wallet passes are a platform feature rather than something a website can generate casually. It requires a real developer identity behind it.',
        ]],
        ['The Google Wallet equivalent', [
            'Google Wallet does the same job on Android through a different mechanism: instead of a signed file, the pass is described in a signed token that the Google Wallet service renders. The practical result for the person carrying it is the same, a card in the wallet app with a scannable code.',
            'A platform worth using issues both, because the person you are handing your card to has already chosen their phone.',
        ]],
    ],
    'faq' => [
        ['Does an Apple Wallet business card work without internet?',
         'Yes. The pass is stored on the device, so it displays and shows its code with no connection. Opening a web link from it would still need one.'],
        ['Can a wallet pass be updated after someone adds it?',
         'Yes. The issuer can push an update and installed passes refresh themselves, which is the main advantage over a screenshot.'],
        ['Is there an Android equivalent?',
         'Google Wallet. The mechanism differs but the result is the same: a card stored in the phone\'s wallet app with a scannable code.'],
    ],
    'related' => ['digital-business-card', 'qr-vcard'],
],

'contactless-business-card' => [
    'title'    => 'Contactless business card',
    'ar_title' => 'بطاقة العمل اللاتلامسية',
    'also'     => ['touchless business card', 'tap business card', 'no-touch card'],
    'short'    => 'A contactless business card is any business card handed over without passing a physical object between two people: by QR scan, NFC tap, wallet pass or link. The term covers a method of exchange rather than one technology, and it entered common use during the 2020 pandemic, when handing over paper briefly became socially awkward.',
    'ar_short' => 'بطاقة العمل اللاتلامسية هي أي بطاقة عمل تُسلَّم دون تمرير شيء مادي بين شخصين: عبر مسح رمز QR أو لمسة NFC أو بطاقة محفظة أو رابط. والمصطلح يصف طريقة التبادل لا تقنية بعينها، وقد شاع استعماله خلال جائحة 2020 حين صار تبادل الورق أمراً محرجاً اجتماعياً لفترة.',
    'sections' => [
        ['A category name, not a product', [
            'This is the term that causes the most confusion, because it describes an outcome rather than a mechanism. A QR code on a phone screen is contactless. An NFC tap is contactless. A wallet pass sent over WhatsApp is contactless. They share a property, not an implementation.',
            'Note the small irony in the NFC case: tapping a phone against a card is contact between two objects, but not between two people, which is what the word was reaching for.',
        ]],
        ['Why the term stuck after the reason for it passed', [
            'The phrase spread in 2020 for hygiene reasons that no longer drive purchasing decisions. It survived because the benefits people discovered underneath it turned out to be the durable ones: no reprinting when details change, no transcription, no running out of cards mid-conference, and a record of the exchange.',
            'Buyers today searching this phrase are usually not thinking about hygiene at all. They are looking for a business card that does not have to be reprinted.',
        ]],
        ['Choosing between the contactless methods', [
            'QR is the universal one: any phone with a camera can scan it, at a distance, and it costs nothing. It is the sensible default for everyone in an organisation.',
            'NFC is the fast and impressive one, but it needs the other person to have NFC hardware switched on, so it belongs on a card that also carries a QR. It is worth buying for people who hand over cards constantly rather than for an entire company.',
            'Wallet passes are the durable one, surviving with no signal and updating remotely, and they cost nothing extra to issue alongside the other two.',
        ]],
    ],
    'faq' => [
        ['Is a contactless business card the same as an NFC card?',
         'No. NFC is one way of doing it. A QR code, a shared link and a wallet pass are all contactless too, and a QR code works on far more phones than NFC does.'],
        ['Do I need special hardware for a contactless business card?',
         'Not for the QR, link or wallet-pass methods, which need nothing but the phones both people already carry. Only the NFC method needs a physical chip.'],
        ['Are contactless business cards still relevant?',
         'The hygiene argument has faded but the practical ones have not: details stay current without reprinting, contacts are saved without transcription, and you cannot run out mid-conference.'],
    ],
    'related' => ['nfc', 'digital-business-card', 'qr-vcard'],
],

];
