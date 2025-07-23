<?php

require_once __DIR__ . '/../utils/db.php'; // Assumes db.php sets up PDO using config/database.php

$pdo = Database::getConnection();

// ----- Function to generate a version 4 UUID -----
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ----- Function to parse referee name -----
function parse_referee_name($name_str) {
    if (preg_match('/^([^,]+),\s*([^()]+)\s*\(([^)]+)\)/', $name_str, $matches)) {
        $surname = trim($matches[1]);
        $initials = trim($matches[2]);
        $first_name = trim($matches[3]);

        // Check for Dutch prefixes in initials
        if (preg_match('/([A-Z.]+)\s+(van|de|der|van der|van de|den)\s*$/i', $initials, $prefix_matches)) {
            $prefix = $prefix_matches[2];
            $last_name = $prefix . ' ' . $surname;
        } else {
            $last_name = $surname;
        }
    } else {
        // Default fallback: treat whole string as first_name, empty last_name
        $first_name = trim($name_str);
        $last_name = '';
    }

    return ['first_name' => $first_name, 'last_name' => $last_name];
}

// ----- Raw rows data (extracted from the provided sheet, without "rowX: " prefix) -----
$raw_rows = [
    'Aart, W van der (Wesley),wvanderaart@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid West,Tientjeslid (BASSETS RC THE)',
    'Ankone, B (Bouke),bouke.ankone@gmail.com,C: 2e klasse heren, Colts Plate,District Oost,',
    'Assman, M.J. (Michael),michael.assman67@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Noord West, District Midden,',
    'Backer, J. (Jeffry),j.backer@ziggo.nl,C: 2e klasse heren, Colts Plate,District Noord West,Recreant (DEN HELDER RC)',
    'Barnhoorn, S (Serge),serge@barnhoorn.eu,B: Ereklasse dames, 1e klasse heren, Colts cup,District Noord West,Tientjeslid (VRN)',
    'Bett, T (Tim),timbett@xs4all.nl,Beoordelaar,District Zuid West,',
    'Binns, T (Thomas),thomas.binns@hotmail.co.uk,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid,',
    'Blaas, C.L.N.M. (Kees),keesblaas@planet.nl,Scheidsrechter coach,District Midden,Tientjeslid (Utrechtse Rugby Club)',
    'Bras, M. (Marit),maritbras@gmail.com,C: 2e klasse heren, Colts Plate,District Noord West,Spelend lid (HAARLEM RFC)',
    'Broek, A. van den (Arne),arne.broek@kpnmail.nl,Beoordelaar,District Zuid,Tientjeslid (RCE (RC Eindhoven))',
    'Broersma, O. (Obed),obroersma@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid West,',
    'Bronkhorst, C.G. (Carl Garth),carl.bronkz101@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid,Tientjeslid (DUKES RC THE)',
    'Brucciani, T (Thomas),tom@brucciani.co.uk,Extra,District Oost,',
    'Bruijn, P. (Paul),paulbruyn@gmail.com,Scheidsrechter coach,District Zuid West,Tientjeslid (Rotterdamse Rugby Club)',
    'Bruin, E. de (Ed),eddydebruin@xs4all.nl,C: 2e klasse heren, Colts Plate,District Midden,',
    'Brummelman, M (Marloes),marloes.brummelman@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid,Spelend lid (M.M.R.C.)',
    'Buist, M. (Marinus),marinus.buist@gmail.com,C: 2e klasse heren, Colts Plate,District Oost,Recreant (Rugbyclub The Big Bulls)',
    'Burbach, M (Max),mh.burbach@gmx.de,C: 2e klasse heren, Colts Plate,District Oost,Tientjeslid (Rugby Club Aachen e.V)',
    'Buys, J.C. (Chris),chrisbuys22@gmail.com,Extra,District Zuid,Tientjeslid (OEMOEMENOE EZRC)',
    'Capello, A (Ad),wedstrijdsecretaris@rugbyroosendaal.nl,Beoordelaar,District Zuid,Tientjeslid (RC RCC )',
    'Coronel, S (Stefan),steef.coronel@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Noord West,Tientjeslid (ASCRUM A.S.R.V.)',
    'D\'Ambrosio, F (Federico),federico@dambrosio.nl,C: 2e klasse heren, Colts Plate,District Midden,Tientjeslid (VRN)',
    'Denley, B (Brian),bddenley@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid West,Tientjeslid (GOUDA RFC)',
    'Dijkstra, M. (Mireya),mireyadijk@gmail.com,Extra,District Noord,Tientjeslid (GREATE PIER RC)',
    'Duiverman, K.J. (Kees Jan),kees.jan.duiverman@gmail.com,Beoordelaar,District Zuid West,',
    'Engelse, L den (Lucas),lucas004848@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Midden,Tientjeslid (NIEUWEGEIN RC)',
    'Estanga, Y (Yeraldin),yeral.rugby.referee@gmail.com,C: 2e klasse heren, Colts Plate,District Noord West,Tientjeslid (VRN)',
    'Faassen, R. van (Rutger),rutgervf@gmail.com,Extra,District Oost,Recreant (Rugbyclub The Big Bulls)',
    'Gerwen, LLF van (Louis),louisvangerwen@hotmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid,Tientjeslid (TSRC TARANTULA)',
    'Glaser, S. (Stephan),stephan@glsr.nl,B: Ereklasse dames, 1e klasse heren, Colts cup,District Noord West,',
    'Grillis, R.M.F. (Ruben),ruben.grillis@hotmail.com,C: 2e klasse heren, Colts Plate,District Oost,',
    'Hansmeier, A (Annabell),annabellhansmeier@gmx.de,C: 2e klasse heren, Colts Plate,District Noord West,Tientjeslid (SMUGGLERS RC THE)',
    'Hawkins, M (Mike),lostohawk@live.com,Extra,District Noord West,Tientjeslid (VRN)',
    'Heijer, E. den (Eelco),eelcodh@hotmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid West,Tientjeslid (DELFT RC)',
    'Heuff, D (Dirk),djheuff@gmail.com,Scheidsrechter coach,District Zuid West,Tientjeslid (Rotterdamse Studenten Rugby Club)',
    'Hoed, M. van den (Mattijs),mattijsvandenhoed@freedom.nl,B: Ereklasse dames, 1e klasse heren, Colts cup,District Noord,Social Rugby (GRONINGEN RC)',
    'Hoyer, M (Mike),referee@rugby-club-aachen.de,Scheidsrechter coach,District Zuid,',
    'Huiskamp, H.W. (Erwin),h.w.huiskamp@gmail.com,Beoordelaar,District Midden,Tientjeslid (GOOI RC \'T)',
    'Lancashire, R (Richard),richard.lancashire@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid West,Recreant (RC DIOK)',
    'Letaif, W (Wissem),wissemltaifrugby@gmail.com,A: Ereklasse heren,District Noord West,Tientjeslid (AMSTERDAMSE AC)',
    'Looten, L (Lars),lad.looten@gmail.com; elooten@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Oost,Spelend lid (WASPS NRC THE)',
    'Maaijen, K. (Koen),k.maaijen@gmail.com,A: Ereklasse heren,District Zuid West,Tientjeslid (GOUDA RFC)',
    'Maintz, A (Antoine),antoine.maintz@upcmail.nl,B: Ereklasse dames, 1e klasse heren, Colts cup,District Midden,Tientjeslid (Rugby Club Hilversum)',
    'Meijer, H.A.W. (Riëtte),riettemeijer@gmail.com,Extra,District Midden,Tientjeslid (RUS)',
    'Meyer, PJ John (Phillip),phillipmeyer22@gmail.com,C: 2e klasse heren, Colts Plate,District Oost,Recreant (PIGS ARC THE)',
    'Mostert, R. (Reinier),reintjemos@hotmail.com,Extra,District Zuid West,Recreant (Rugbyclub Hoek van Holland)',
    'Naulais, M.J.R. (Mika),m.naulais@gmail.com,Extra,District Zuid West,Tientjeslid (L.S.R.G.)',
    'O Shaughnessy, COS (Colin),colinoshocks@gmail.com,Extra,District Zuid West,Tientjeslid (BASSETS RC THE)',
    'Oliver, A. (Andrew),aforugbyref@hotmail.com,C: 2e klasse heren, Colts Plate,District Zuid,Tientjeslid (OISTERWIJK OYSTERS RFC)',
    'Oudman, BJ (Bram),bram.oudman.referee@gmail.com,A: Ereklasse heren,District Oost,Recreant (EMMEN RUGBY CLUB)',
    'O’Connell, D (Dan),daniel.x.oconnell@gmail.com,A: Ereklasse heren,District Zuid,Tientjeslid (Rugby Club Aachen e.V)',
    'Pardede, J.P. (Jens),jens@dengar.nl,A: Ereklasse heren,District Zuid West,Spelend lid (GOUDA RFC)',
    'Pardede, T.B. (Tjerk),tjerk@dengar.nl,Extra,District Midden,Spelend lid (Utrechtse Studenten Rugby Society)',
    'Ploeger, P. (Peter),peter.ploeger@gmail.com,C: 2e klasse heren, Colts Plate,District Oost,Spelend lid (ERC\'69)',
    'Plomp, S (Simon),smn.plomp@gmail.com,Beoordelaar,District Midden,',
    'Pol, R. van de (Rene),renevdpol@hotmail.com,Extra,District Zuid,Recreant (OISTERWIJK OYSTERS RFC)',
    'Pouwels, T (Thomas),thomas16839@gmail.com,C: 2e klasse heren, Colts Plate,District Zuid West,',
    'Prevaes, B. (Bert),bert@prevaes.nl,Beoordelaar,District Midden,',
    'Puijpe, J.J.M.H.A. (Joost),joostpuype@hotmail.com,Beoordelaar,District Noord West,Tientjeslid (AMSTELVEENSE RC)',
    'Raynor, N (Nathan),n.f.raynor@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid West,',
    'Referee 1, E (Exchange),garyjr9515@gmail.com,Extra,District Midden,Tientjeslid (VRN)',
    'Referee 2, E (Exchange),ondrej947@gmail.com,Extra,District Midden,Tientjeslid (VRN)',
    'Referee 3, E (Exchange),jonas.dolezil@protonmail.com,Extra,District Midden,Tientjeslid (VRN)',
    'Riepe, C (Conrad),conrad.riepe@googlemail.com,A: Ereklasse heren,District Zuid,',
    'Ritchie, K (Katherine),katherine.ritchie@btinternet.com,A: Ereklasse heren,District Zuid West,Tientjeslid (Rotterdamse Studenten Rugby Club)',
    'Rooyen, B van (Bruce),brucethomas.vr@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid West,',
    'Rouwet, P (Pablo),p.rouwet@gmail.com,C: 2e klasse heren, Colts Plate,District Noord West,Recreant (ASCRUM A.S.R.V.)',
    'Ruiter, H.L.G. de (Henrie),hlg.ruiter@gmail.com,Beoordelaar,District Zuid,',
    'Smits, P. (Pieter),pag.smits@hotmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid West,Recreant (Rugbyclub Hoek van Holland)',
    'Spek, E Van der (Edwin),edwinvdspek.rugby@gmail.com,A: Ereklasse heren,District Noord West,Tientjeslid (HAARLEM RFC)',
    'Statham, J (James),james.statham1@btinternet.com; claudiastatham@live.com,C: 2e klasse heren, Colts Plate,District Zuid West,',
    'Stevens, A. (Andrew),ajstevens97@outlook.com,Beoordelaar,District Zuid,Tientjeslid (OCTOPUS RC)',
    'Taljaard, D.J. (Diederick),dj.taljaard@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid West,',
    'Teerink, F. (Friso),frisoteerink@gmail.com,Beoordelaar,District Noord West,Tientjeslid (RUSH SRC)',
    'Velden, R van der (Rudolf),vdvruud@gmail.com,Beoordelaar,District Midden,Tientjeslid (Utrechtse Rugby Club)',
    'Veldmaat, J. (Joris),j.veldmaat@gmail.com,C: 2e klasse heren, Colts Plate,District Oost,',
    'Verseveld, F.J.A. van (Fred),fred.van.verseveld@gmail.com,C: 2e klasse heren, Colts Plate,District Midden,Tientjeslid (GOOI RC \'T)',
    'Visser, G. (Gert),visser.gert.j@gmail.com,A: Ereklasse heren,District Midden,Tientjeslid (Utrechtse Rugby Club)',
    'Vliet, J. van der (Hans),hnsvdvliet@gmail.com,Beoordelaar,District Zuid West,Tientjeslid (DELFT RC)',
    'Vries, H. de (Henk),rugbyhenk@outlook.com,C: 2e klasse heren, Colts Plate,District Noord,Spelend lid (GRONINGEN RC)',
    'Vries, M. de (Michael),vries.vries@ziggo.nl,C: 2e klasse heren, Colts Plate,District Noord West,Tientjeslid (WATERLAND RC)',
    'Wadey, D (Darron),achillesagain@gmail.com,Beoordelaar,District Noord West,Tientjeslid (WATERLAND RC)',
    'Wartena, S. (Sjoerd),swartena@casema.nl,Beoordelaar,District Zuid,Recreant (Etten-Leur RC)',
    'Weir, D (Dennis),dennis.weir.rugby@gmail.com,Extra,District Oost,Tientjeslid (VRN)',
    'Welle Donker, G. (Guus),guuswelledonker@gmail.com,B: Ereklasse dames, 1e klasse heren, Colts cup,District Zuid West,Recreant (WRC-Te Werve RUFC)',
    'Wolfenden, I. (Ian),klmblue69@hotmail.com,Extra,District Noord West,Tientjeslid (AMSTELVEENSE RC)',
    'Wright, L (Liam),liamewright@gmail.com,A: Ereklasse heren,District Noord West,Tientjeslid (HAARLEM RFC)',
    'Zandvliet, J.P. (Joop),referee@joopzandvliet.nl,Beoordelaar,District Noord West,Spelend lid (ALKMAARSE R.U.F.C.)',
    'Clarke, D (Don),don_clarke_bss@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Tientjeslid (RUS)',
    'Doughty, M.J. (Martin),doughtymartin@aol.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Tientjeslid (Utrechtse Rugby Club)',
    'Feller, MM (Max),max.feller@kpnmail.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Spelend lid (BULLDOGS ALMERE RC)',
    'Hagens, L.G.L.M. (Luuk),luukhagens@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Tientjeslid (Stichtsche Rugby Football Club)',
    'Koops, K (Klaas),koopsklaas@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Recreant (SPAKENBURG RC)',
    'Luteijn, E.P.A. (Eric),eluteijn@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Tientjeslid (GOOI RC \'T)',
    'Onsoz, A (Aylin),aylinonsoz@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Tientjeslid (Utrechtse Rugby Club)',
    'Ruijter, SM de (Shaquil),shaquil11@outlook.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Spelend lid (Rugby Club Eemland)',
    'Silbernberg, AP (Allain),allains27@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,',
    'Tolboom, A.G. (Ton),tontolboom@outlook.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,',
    'Verveer, H (Hans),hlverveer@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Midden,Tientjeslid (SPAKENBURG RC)',
    'Albers, J (Jan),jan.albers@icloud.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Tientjeslid (ASCRUM A.S.R.V.)',
    'Beltman, A. (Arend),beltmanarend@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,',
    'Bras, M (Martijn),brasvaneden@upcmail.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Tientjeslid (HAARLEM RFC)',
    'Fellenberg Van der Molen, A (Andres),a.fellenberg@green-partner.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,',
    'Fellenberg van der Molen, L (Lucas),lucas.fellenberg@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Spelend lid (Zaandijk Rugby)',
    'Goos, H.J. (Hendrik),hendrikgoos@yahoo.co.uk,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Tientjeslid (RUSH SRC)',
    'Hille Ris Lambers, T (Ties),tieshrl@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Tientjeslid (Rotterdamse Studenten Rugby Club)',
    'Keyser, G (Gawie),gawie.keyser@me.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Tientjeslid (ALKMAARSE R.U.F.C.)',
    'Kouwenhoven, I (Ino),ino@quicknet.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Tientjeslid (ALKMAARSE R.U.F.C.)',
    'Mooij, S de (Sem),semdemooij@icloud.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Spelend lid (CASTRICUMSE RC), Tientjeslid (WATERLAND RC)',
    'Oosterbeek, S.C.M. (Steijn),frank.oosterbeek@gmail.com; steijnoosterbeek@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,Spelend lid (AMSTELVEENSE RC)',
    'Victor, JA (Jaco),jaco.victor19@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord West,',
    'Butselaar, B.P. van (Bob),bvb88@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,',
    'Delft, ALJ van (Amber),a.l.j.vandelft@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Recreant (GRONINGEN RC)',
    'Denkers, R (Rick),drsdenkers@outlook.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Tientjeslid (EMMEN RUGBY CLUB)',
    'Eijnatten, M van (Maurits),combustiblewater@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Social Rugby (GRONINGEN RC)',
    'Hokse, H.F. (Harro),Voorzitter@thebigstones.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Spelend lid (Rugby Club The Big Stones)',
    'Jaspers Focks, L.M (Loes),loesjf@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Recreant (GRONINGEN RC)',
    'Kizito Bugembe, D (Deusdedit),deuskhalifa@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Social Rugby (Rugby Club Sneek)',
    'Pas, T.H. ten (Tom),tomtpas@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Spelend lid (GRONINGEN RC)',
    'Roling, A.H.P. (Albert),ahp.roling@ziggo.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,',
    'Skilton, D (David),david3467@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Recreant (PHOENIX DRC)',
    'Sondorp, LHJ (Luc),l.h.j.sondorp@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Spelend lid (GRONINGEN RC)',
    'Stoeten, HJ (Hendrik Jan),hendrikjanstoeten@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Spelend lid (FEANSTER RC)',
    'Verlaan, A. (Arthur),a.verlaan@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,',
    'Vries, GP De (GP),rugbygp57@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,',
    'Wagemakers, A (Ad),ad.jeanneke.wagemakers@planet.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Veteranen Rugby (Rugby Club The Big Stones)',
    'Weenink, Y. (Yoeri),yweenink@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Noord,Recreant (DWINGELOO RC)',
    'Ansell, D.J. (Derek),derek_ansell@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,',
    'Brakel, G. van (Gerard),Molenweg10@me.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (ASCRUM A.S.R.V.)',
    'Brinkman, N (Nouri),nourinouri@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (PIGS ARC THE)',
    'Dommelen, J van (Jeroen),jeroendommel@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (WAGENINGEN RC)',
    'Ehren, S (Stijn),stijn.ehren@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (ASCRUM A.S.R.V.)',
    'Francke, K (Kai),kaifrancke@hotmail.de,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,',
    'Koeverden, M. van (Michiel),michielvankoeverden@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (ZWOLLE RC)',
    'Kramer, J.A. (Jolanda),jolanda.kramer@outlook.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (PIGS ARC THE)',
    'Luijkx, P.C.L.M. (Patrick),luijkx@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (OBELIX NSRV)',
    'Murray, L (Lorcan),lorcan.murray@hotmail.co.uk,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (PIGS ARC THE)',
    'Nel, M (Martian),martiannel6@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Spelend lid (PIGS ARC THE)',
    'Nijman, J. (John),johnnijman@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,',
    'Popken, D (Daan),daanpopken@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (ZWOLLE RC)',
    'Reinhardt, T (Tiaan),tiaanreinhardt@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (PIGS ARC THE)',
    'Reuvers, P. (Peter),reuverspeter@msn.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (DRC The Wild Rovers)',
    'Roos, M. (Marco),marcoroos0@gmail.com; wedstrijdsecretaris@rcwageningen.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,',
    'Schepman, T.J.J.R (Thijs),thijschepman@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,',
    'Smeets, C. (Cosmo),cosmosmeets@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (PIGS ARC THE)',
    'Utrecht, J van (Johan),jdcvanutrecht@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Tientjeslid (DRC The Wild Rovers)',
    'Veldman, R. (Richard),lgveldman@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Oost,Recreant (WAGENINGEN RC)',
    'Adriaanse, J. (Janwillem),janwillem@gmx.es,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (TILBURG RC)',
    'Berg, M van den (Max),m.h.vandenberg@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,',
    'Boers, K (Koen),koenboers@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,',
    'Bogaers, W.J.A.M. (Pim),bogaers@icloud.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Recreant (ASCRUM A.S.R.V.)',
    'Bommel, T. van (Twan),bomme01@kpnmail.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (OISTERWIJK OYSTERS RFC)',
    'Boom, T van den (Tom),tomvdboom@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Spelend lid (ELEPHANTS ESRC THE)',
    'Bouwens, W (Wilco),wilco.bouwens@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (RC RCC )',
    'Companjen, T (Tijn),tijncompanjen@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Spelend lid (Utrechtse Studenten Rugby Society)',
    'Cremers, B (Bart),bja.cremers@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Spelend lid (BREDASE RUGBY CLUB)',
    'Demba, A.G.M. (Aïsha),aishademba@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (ELEPHANTS ESRC THE)',
    'Derks, H.P.M. (Harm),harmderks96@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Spelend lid (ELEPHANTS ESRC THE)',
    'Hanegraaf, B. (Bram Onno),bramhanegraaf@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Spelend lid (OCTOPUS RC)',
    'Jacobi, J (Jeroen),jacobijeroen@gmail.com,Extra,District Zuid,Tientjeslid (BREDASE RUGBY CLUB)',
    'Koekkoek, E. (Etienne),etiennekoekkoek@gmail.com,Extra,District Zuid,Spelend lid (BREDASE RUGBY CLUB)',
    'Leenders, P. (Peter),peter.leenders@chello.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,',
    'Mooijman, M.H. (Martijn),mhmooijman76@yahoo.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (VETS VRC THE)',
    'Reede, T.C.R (Thies),reedecurfs@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Spelend lid (M.M.R.C.)',
    'Renate Janssen, S (Scheidsrechter),renate.janssen@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (ELEPHANTS ESRC THE)',
    'Rijo Mato, M (Matias),matias.rijo@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (TSRC TARANTULA)',
    'Schalkwijk, J. (Jan),jan.schalkwijk@gmx.net,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,',
    'Schoorel, A (Alex),alex0103schoorel@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Recreant (OISTERWIJK OYSTERS RFC)',
    'Têtu, L (Luc),l.tetu@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (RC RCC )',
    'Vermeulen, M. (Mark),zondag11@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,',
    'Wijdeven, J (Joop),joop.wijdeven@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid,Tientjeslid (WAGENINGEN RC)',
    'Aldama, E. (Eduardo),eardv@yahoo.es,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (DELFT RC)',
    'Angelucci, S (Stefano),stefspace@yahoo.it,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (Voorburgse Rugby Club)',
    'Bent, JCP van der (Jeroen),tinekeenjeroen@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (Rugbyclub Hoek van Holland)',
    'Bloem, D (Dirk),dirk.c.bloem@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (The Hague Hornets)',
    'Brouwer, W.J.J. (Wouter),wouter.brouwer1@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (WRC-Te Werve RUFC)',
    'Cuvelier-Paradis, Y (Yannick),yannick.cuvelier@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Driel, G B Van (Bruis),bruis@salax.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (Rotterdamse Rugby Club)',
    'Frankland, R.T. (Richard),Rtfrankland@yahoo.com.au,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Gorkum, T van (Tom),thomasvangorkum@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Guichard, S (Sebastian),sebas.guichard@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (Rotterdamse Rugby Club)',
    'Haan, W. de (Wiebe),wiebedehaan1957@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (GOUDA RFC)',
    'Hokse, M.F. (Marlou),marlou_hokse@kpnmail.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (The Hague Hornets), Tientjeslid (Rugby Club The Big Stones)',
    'Horst, B van der (Bob),bvdhorst2013@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (Voorburgse Rugby Club)',
    'Jansen, WR (Wil),wil@klima.co.za,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Kahana, G. (Guy),guy_kahana@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (Haagsche Rugby Club)',
    'Kampen, CD van (Céline),cedi.vankampen@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Kersbergen, A van (Ap),appokers@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (GOUDA RFC)',
    'Klijnsma, S.D. (Sebastian),s.d.klijnsma@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Krabbendam, J.J.C. (Jeroen),J.Krabbendam2@vandervalknotarissen.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (HERMES RUGBY CLUB)',
    'Leeming, W (will),william_leeming@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Marijnissen, J (Jurre),jurre.m95@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (Sanctus Virgilius RC)',
    'Merwe, DJ van der (David),davidvdmerwe.nl@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (DORDTSCHE RUGBY CLUB), Tientjeslid (Rotterdamse Rugby Club)',
    'Mudde, L. (Lars),lars.mudde@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (BASSETS RC THE)',
    'Mudde, T.R. (Tim),sassem1965@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (BASSETS RC THE)',
    'Noort, A.B.J. van (Antonie),antonievannoort@hotmail.com,E: 4e klasse heren, 2e klasse dames, Jun.Plate,District Zuid West,Spelend lid (GOUDA RFC)',
    'Oostenbroek, H.J. (Hubert),hj@oostenbroek.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (ASCRUM A.S.R.V.)',
    'Petersen, L (Loes),loespetersen@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (L.S.R.G.)',
    'Prein, J (Joep),jprein@xs4all.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (RC DIOK)',
    'Rasch, T. (Tycho),tycho.rasch@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (DELFT RC)',
    'Schoot, G. van der (Gwen),rugbygwen@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (DELFT RC)',
    'Slaghek, E.M. (Erik),emslaghek@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (DELFT RC)',
    'Smith, O S (Oliver),oliverfreemansmith@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (RC DIOK)',
    'Swinkels, C.W. (Christian),christian@swinkels.email,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Tandon, ST (Suparshav),suparshavt@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (L.S.R.G.)',
    'Teijlingen, AS van (Alessio Stèfano),alessiovanteijlingen@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (BASSETS RC THE), Tientjeslid (L.S.R.G.)',
    'Thomson, G (Graham),mauritsthomson@outlook.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (L.S.R.G.)',
    'Vallinga, Z A (Zoë Anne-Klara),zoe@vallinga.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (L.S.R.G.)',
    'Verbrugh, D.B. (Dagmar),dagmarverbrugh@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (GOUDA RFC)',
    'Verweij, B.A. (Bart),bart_verweij@live.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (Sanctus Virgilius RC)',
    'Vingerhoets, M. C. H. (Marc),marc.vingerhoets@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (DORDTSCHE RUGBY CLUB)',
    'Visser, R (Ruben),Ruben.j.visser@gmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Spelend lid (Rotterdamse Studenten Rugby Club)',
    'Voskuil, D (Diederick),diederickvoskuil@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (DSR-C)',
    'Wagner, D (Dennis),denniswagner.bassets@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (BASSETS RC THE)',
    'Weightman, PMH (Magnus),mweightman@yahoo.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Recreant (Rotterdamse Rugby Club)',
    'Zon, DHM van der (Daan),vanderzon@voorpraktijken.nl,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Zuilen, B.L. van (Bas),bas@vanzuilen.net,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,',
    'Zwet, J van der (Joran),joran1407@hotmail.com,D: 3e kl.He, 1e kl.Da, Colts Bo/Sh, Jun.cup,District Zuid West,Tientjeslid (THOR SRC)'
];

// ----- District coordinates map (approximate) -----
$district_coords = [
    'Zuid West' => ['lat' => 51.9244, 'lon' => 4.4777], // Rotterdam
    'Noord West' => ['lat' => 52.3702, 'lon' => 4.8952], // Amsterdam
    'Oost' => ['lat' => 52.2215, 'lon' => 6.8937], // Enschede
    'Midden' => ['lat' => 52.0907, 'lon' => 5.1214], // Utrecht
    'Zuid' => ['lat' => 51.4416, 'lon' => 5.4697], // Eindhoven
    'Noord' => ['lat' => 53.2194, 'lon' => 6.5665]
];

// ----- Parse raw rows and build referees_data -----
$referees_data = [];
foreach ($raw_rows as $row_str) {
    // Match the row pattern
    if (preg_match('/^(.+?),(.+?@.+?),(.*?[A-E]:.+?),(District.+?),(.*?)$/', $row_str, $matches)) {
        $name_str = trim($matches[1]);
        $email = trim($matches[2]);
        $grade_str = trim($matches[3]);
        $district = trim($matches[4]);
        $membership = trim($matches[5]);
    } else {
        echo "Warning: Could not parse row: $row_str\n";
        continue;
    }

    // Extract grade
    if (!preg_match('/^([A-E]):/', $grade_str, $grade_match)) {
        continue; // Skip if no grade
    }
    $grade = $grade_match[1];

    if (!in_array($grade, ['A', 'B', 'C', 'D'])) {
        continue; // Skip if not A-D (as per user instruction; E is skipped)
    }

    // Parse name
    $name_parts = parse_referee_name($name_str);
    $first_name = $name_parts['first_name'];
    $last_name = $name_parts['last_name'];

    if (empty($first_name) || empty($last_name)) {
        echo "Warning: Could not parse name from: $name_str\n";
        continue;
    }

    // Extract district name (remove "District ")
    $district_name = trim(str_replace('District', '', $district));

    // Get coords or default
    $base_lat = $district_coords[$district_name]['lat'] ?? 52.1326;
    $base_lon = $district_coords[$district_name]['lon'] ?? 5.2913; // Default to Netherlands center if unknown
    $home_lat = $base_lat + (mt_rand(-100, 100) / 10000);
    $home_lon = $base_lon + (mt_rand(-100, 100) / 10000);

    // Random AR grade A-D
    $ar_grades = ['A', 'B', 'C', 'D'];
    $ar_grade = $ar_grades[array_rand($ar_grades)];

    // Build referee data
    $referees_data[] = [
        'uuid' => generate_uuid_v4(),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'phone' => null, // No phone in data
        'home_club_id' => null, // Ignored
        'home_location_city' => $district_name,
        'grade' => $grade,
        'ar_grade' => $ar_grade,
        'home_lat' => $home_lat,
        'home_lon' => $home_lon
    ];
}

// ----- Seed Referees -----
echo "Seeding Referees...\n";
$seeded_referees_count = 0;
$existing_referees_count = 0;
$stmt_insert_referee = $pdo->prepare("
    INSERT IGNORE INTO referees 
        (uuid, referee_id, first_name, last_name, email, phone, home_club_id, home_location_city, grade, ar_grade, home_lat, home_lon) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($referees_data as $index => $ref) {
    // Generate referee_id
    $referee_id = 'REF' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);

    // Check if exists (by email, assuming unique)
    $stmt_check_referee = $pdo->prepare("SELECT uuid FROM referees WHERE email = ?");
    $stmt_check_referee->execute([$ref['email']]);
    if ($stmt_check_referee->fetch()) {
        $existing_referees_count++;
    } else {
        $stmt_insert_referee->execute([
            $ref['uuid'],
            $referee_id,
            $ref['first_name'],
            $ref['last_name'],
            $ref['email'],
            $ref['phone'],
            $ref['home_club_id'],
            $ref['home_location_city'],
            $ref['grade'],
            $ref['ar_grade'],
            $ref['home_lat'],
            $ref['home_lon']
        ]);
        $seeded_referees_count++;
    }
}
echo "Referees: {$seeded_referees_count} seeded, {$existing_referees_count} already existed.\n";

?>