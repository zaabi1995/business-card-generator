<?php
return [
    'headline'      => 'اعتمدوها لفرق العمل لديهم',
    // Arabic counted-noun agreement follows the LAST TWO DIGITS:
    //   3-10  -> plural genitive      (4 موظفين)
    //   11-99 -> singular accusative  (65 موظفاً)
    // 265 therefore takes the singular form because 65 does. One string
    // cannot serve both, so the partial picks between these two.
    'people_few'    => ':n موظفين يحملون البطاقة',
    'people_many'   => ':n موظفاً يحملون البطاقة',
];
