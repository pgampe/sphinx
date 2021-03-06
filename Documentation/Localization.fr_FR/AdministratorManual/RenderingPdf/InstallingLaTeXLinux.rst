﻿.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. include:: ../../../Includes.txt


Installation de LaTeX sous Linux ou Mac OS X
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Votre distribution sysème ou vendeur fournit très probablement un paquet TeX comprenant LaTeX. Veuillez rechercher votre
source de logiciels usuelle pour un paquet TeX ; ou alors installez `TeX Live`_ directement.

.. _`TeX Live`: http://www.tug.org/texlive/

.. note::

	Les fichiers LaTeX produits utilisent plusieurs bibliothèques LaTeX qui peuvent ne pas être disponibles avec une
	distribution "minimale" de TeX. Pour Tex Live, les composants suivants doivent être installés en plus :

	- latex-recommended
	- latex-extra
	- fonts-recommended
	- fonts-extra

	Le composant "fonts-extra" est facultatif mais recommandé pour un rendu optimal des caractères spéciaux de certains
	manuels.

Linux Debian
""""""""""""

Vous pouvez exécuter la commande suivante pour installer les composants requis :

.. code-block:: bash

	$ sudo apt-get install texlive-base texlive-latex-recommended \
	  texlive-latex-extra texlive-fonts-recommended texlive-fonts-extra

Afin de compiler en PDF, cette extension nécessite à la fois :program:`pdflatex` (qui fait partie du paquet ``texlive-latex-extra``)
et :program:`make`:

.. code-block:: bash

	$ sudo apt-get install make


Mac OS X
""""""""

Vous pouvez installer un environnement TeX Live en utilisant le paquet MacTeX_. Autrement, si vous êtes habitué à utiliser
MacPorts_, le processus est similaire à un système Debian :

.. code-block:: bash

	$ sudo port install texlive texlive-latex-extra


.. _MacTeX: http://www.tug.org/mactex/

.. _MacPorts: http://www.macports.org/
